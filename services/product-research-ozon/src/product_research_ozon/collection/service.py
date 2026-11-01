"""One bounded source page per step; scheduling/retry policy belongs to stage 5."""

import hashlib
import logging
import time
from collections.abc import Callable
from datetime import datetime
from uuid import UUID, uuid7

from product_research_ozon.collection.ports import (
    ItemsSource,
    SnapshotRepository,
    SnapshotStorage,
)
from product_research_ozon.domain.catalog import utc_now
from product_research_ozon.domain.month import (
    PAGE_SIZE,
    CollectionFailure,
    CollectionLimits,
    MonthParameters,
    Snapshot,
    SnapshotStatus,
    parse_page,
)
from product_research_ozon.domain.storage import ManifestRef, PageRef
from product_research_ozon.infrastructure.observability import Event, emit
from product_research_ozon.snapshots.manifest import manifest_body


class MonthCollector:
    def __init__(
        self,
        repository: SnapshotRepository,
        storage: SnapshotStorage,
        source: ItemsSource,
        clock: Callable[[], datetime] = utc_now,
        logger: logging.Logger | None = None,
    ) -> None:
        self._repository, self._storage, self._source, self._clock = (
            repository,
            storage,
            source,
            clock,
        )
        self._logger = logger or logging.getLogger("product-research-ozon")

    def start(
        self,
        company_id: UUID,
        source_id: UUID,
        parameters: MonthParameters,
        limits: CollectionLimits,
    ) -> Snapshot:
        return self._repository.create(
            company_id,
            source_id,
            uuid7(),
            parameters,
            limits,
            self._source.generation,
            self._clock(),
        )

    def step(
        self,
        company_id: UUID,
        source_id: UUID,
        snapshot_id: UUID,
        trace_id: str | None = None,
    ) -> Snapshot:
        key = company_id, source_id, snapshot_id
        value = self._repository.get(*key)
        if value.status != SnapshotStatus.COLLECTING:
            return value
        if value.generation != self._source.generation:
            raise CollectionFailure("obsolete_generation")
        owner, started = uuid7(), time.monotonic_ns()
        if not self._repository.claim(
            *key, owner, self._source.generation, self._clock()
        ):
            return self._repository.get(*key)
        outcome, error_code = "collecting", None
        try:
            # Reload after the atomic claim: the previous owner may have advanced the cursor.
            value = self._repository.get(*key)
            if value.total is None or value.cursor < value.total:
                if value.pages >= value.limits.max_pages:
                    raise CollectionFailure("budget_exceeded")
                body = self._source.fetch(
                    company_id,
                    source_id,
                    value.parameters,
                    value.cursor,
                    value.cursor + PAGE_SIZE,
                )
                page = parse_page(body, value.cursor, value.cursor + PAGE_SIZE)
                if (
                    page.total > value.limits.max_rows
                    or max(1, (page.total + PAGE_SIZE - 1) // PAGE_SIZE)
                    > value.limits.max_pages
                    or value.byte_size + len(body) > value.limits.max_bytes
                ):
                    raise CollectionFailure("budget_exceeded")
                if value.total is not None and value.total != page.total:
                    raise CollectionFailure("incomplete")
                ref = PageRef(
                    company_id, source_id, snapshot_id, value.pages, page.sha256
                )
                self._storage.save_page(company_id, ref, body)
                value = self._repository.append(*key, owner, page)
            if value.total == value.cursor:
                completed_at = self._clock()
                body = manifest_body(value, self._repository.pages(*key), completed_at)
                sha = hashlib.sha256(body).hexdigest()
                self._storage.save_manifest(
                    company_id,
                    ManifestRef(company_id, source_id, snapshot_id, sha),
                    body,
                )
                value = self._repository.finish(
                    *key, owner, sha, len(body), completed_at
                )
                outcome = "ready"
            else:
                self._repository.release(*key, owner)
            return value
        except CollectionFailure as error:
            error_code = error.code
            outcome = (
                "deferred"
                if error.code in ("rate_limited", "report_pending")
                else "error"
            )
            try:
                self._repository.release(*key, owner, error)
            except CollectionFailure:
                # Lease expiration remains the recovery path when the DB itself is unavailable.
                pass
            raise
        finally:
            emit(
                self._logger,
                Event(
                    company_id=str(company_id),
                    source_connection_id=str(source_id),
                    job_id=str(snapshot_id),
                    trace_id=trace_id,
                    resource_type="month_snapshot",
                    outcome=outcome,
                    error_class=error_code,
                    duration_ms=(time.monotonic_ns() - started) // 1000000,
                ),
                logging.WARNING
                if outcome == "deferred"
                else logging.ERROR
                if outcome == "error"
                else logging.INFO,
            )

    def read_manifest(
        self, company_id: UUID, source_id: UUID, snapshot_id: UUID
    ) -> bytes:
        value = self._ready(company_id, source_id, snapshot_id)
        if value.manifest_sha256 is None or value.completed_at is None:
            raise CollectionFailure("corrupt_raw")
        body = self._storage.read_object(
            company_id,
            ManifestRef(company_id, source_id, snapshot_id, value.manifest_sha256),
        )
        if len(body) != value.manifest_size or body != manifest_body(
            value,
            self._repository.pages(company_id, source_id, snapshot_id),
            value.completed_at,
        ):
            raise CollectionFailure("corrupt_raw")
        return body

    def read_page(
        self, company_id: UUID, source_id: UUID, snapshot_id: UUID, page_no: int
    ) -> bytes:
        self._ready(company_id, source_id, snapshot_id)
        page = self._repository.page(company_id, source_id, snapshot_id, page_no)
        body = self._storage.read_object(
            company_id,
            PageRef(company_id, source_id, snapshot_id, page.page_no, page.sha256),
        )
        if len(body) != page.byte_size:
            raise CollectionFailure("corrupt_raw")
        return body

    def _ready(self, company_id: UUID, source_id: UUID, snapshot_id: UUID) -> Snapshot:
        value = self._repository.get(company_id, source_id, snapshot_id)
        if value.status != SnapshotStatus.READY:
            raise CollectionFailure("not_found")
        return value
