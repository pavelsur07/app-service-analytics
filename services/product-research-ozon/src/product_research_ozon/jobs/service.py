import logging
import time
from collections.abc import Callable
from datetime import datetime
from typing import Protocol
from uuid import UUID

from product_research_ozon.domain.catalog import CatalogUnavailable, utc_now
from product_research_ozon.domain.jobs import (
    JobLease,
    RetryDecision,
    RetryPolicy,
    RunSpec,
)
from product_research_ozon.domain.month import (
    CollectionFailure,
    CollectionLimits,
    Snapshot,
)
from product_research_ozon.infrastructure.observability import Event, emit


class RunRepository(Protocol):
    def accept(
        self,
        company_id: UUID,
        spec: RunSpec,
        limits: CollectionLimits,
        generation: int,
        now: datetime,
    ) -> dict[str, object]: ...
    def generation_current(self, source_id: UUID, generation: int) -> bool: ...
    def claim_next_for_all_companies(
        self, source_id: UUID, generation: int, allowed: tuple[UUID, ...], now: datetime
    ) -> JobLease | None: ...
    def state(self, company_id: UUID, lease: JobLease) -> Snapshot: ...
    def apply(
        self,
        company_id: UUID,
        lease: JobLease,
        decision: RetryDecision,
        observed: Snapshot,
        now: datetime,
    ) -> bool: ...
    def reject_run_credentials(
        self, company_id: UUID, lease: JobLease, now: datetime
    ) -> bool: ...
    def progress(
        self, company_id: UUID, source_id: UUID, run_id: UUID
    ) -> dict[str, object]: ...
    def metadata(
        self, company_id: UUID, source_id: UUID, snapshot_id: UUID
    ) -> dict[str, object]: ...


class RunService:
    def __init__(
        self,
        repository: RunRepository,
        source_id: UUID,
        generation: int,
        limits: CollectionLimits,
        allowed: tuple[UUID, ...],
        clock: Callable[[], datetime] = utc_now,
    ) -> None:
        (
            self._store,
            self.source_id,
            self._generation,
            self._limits,
            self._allowed,
            self._clock,
        ) = repository, source_id, generation, limits, frozenset(allowed), clock

    def submit(self, company_id: UUID, payload: object) -> dict[str, object]:
        self._allow(company_id)
        try:
            spec = RunSpec.from_payload(payload, self._clock())
        except ValueError, TypeError, CatalogUnavailable:
            raise CollectionFailure("contract_violation") from None
        if spec.company_id != company_id or spec.source_id != self.source_id:
            raise CollectionFailure("conflict")
        return self._store.accept(
            company_id, spec, self._limits, self._generation, self._clock()
        )

    def progress(self, company_id: UUID, run_id: UUID) -> dict[str, object]:
        self._allow(company_id)
        return self._store.progress(company_id, self.source_id, run_id)

    def metadata(self, company_id: UUID, snapshot_id: UUID) -> dict[str, object]:
        self._allow(company_id)
        return self._store.metadata(company_id, self.source_id, snapshot_id)

    def _allow(self, company_id: UUID) -> None:
        if company_id not in self._allowed:
            raise CollectionFailure("not_available")


class RunWorker:
    def __init__(
        self,
        repository: RunRepository,
        source_id: UUID,
        generation: int,
        allowed: tuple[UUID, ...],
        execute: Callable[[JobLease], Snapshot],
        clock: Callable[[], datetime] = utc_now,
        logger: logging.Logger | None = None,
    ) -> None:
        (
            self._store,
            self._source,
            self._generation,
            self._allowed,
            self._execute,
            self._clock,
        ) = repository, source_id, generation, allowed, execute, clock
        self._logger = logger or logging.getLogger("product-research-ozon")

    def run_once(self) -> bool:
        if not self._store.generation_current(self._source, self._generation):
            return False
        lease = self._store.claim_next_for_all_companies(
            self._source, self._generation, self._allowed, self._clock()
        )
        if lease is None:
            return False
        started = time.monotonic_ns()
        failure: CollectionFailure | None = None
        terminal_error: str | None = None
        try:
            observed = self._store.state(lease.company_id, lease)
            if lease.auth_paused:
                # A paused source bypasses even a snapshot-level cooldown.
                failure = CollectionFailure("authorization_failed")
            elif lease.unknown_outcome:
                # An expired execution with no durable outcome consumes one conservative failure.
                failure = CollectionFailure("source_unavailable")
            elif lease.pending_failure:
                # A saved release was not acknowledged; apply it once before any new I/O.
                failure = CollectionFailure(
                    observed.error_code or "contract_violation",
                    observed.next_attempt_at,
                )
            elif observed.status != "ready" and self._clock() < lease.deadline:
                try:
                    self._execute(lease)
                except CollectionFailure as error:
                    failure = error
            observed = self._store.state(lease.company_id, lease)
            if failure is not None and failure.code == "authorization_failed":
                if self._store.reject_run_credentials(
                    lease.company_id, lease, self._clock()
                ):
                    return True
                failure = CollectionFailure("obsolete_generation")
            decision = RetryPolicy.decide(lease, observed, failure, self._clock())
            terminal_error = decision.error_code
            if not self._store.apply(
                lease.company_id, lease, decision, observed, self._clock()
            ):
                terminal_error = "lease_lost"
            return True
        finally:
            safe_code = terminal_error or (failure.code if failure else None)
            waiting = safe_code in ("rate_limited", "report_pending")
            emit(
                self._logger,
                Event(
                    company_id=str(lease.company_id),
                    source_connection_id=str(lease.source_id),
                    job_id=str(lease.job_id),
                    trace_id=str(lease.owner),
                    resource_type="research_job",
                    outcome="deferred" if waiting else "error" if safe_code else "ok",
                    error_class=safe_code,
                    duration_ms=(time.monotonic_ns() - started) // 1000000,
                ),
                logging.WARNING
                if waiting
                else logging.ERROR
                if safe_code
                else logging.INFO,
            )
