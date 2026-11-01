"""Frozen service inputs and bounded retry decisions, without I/O."""

import hashlib
import json
import re
from dataclasses import dataclass
from datetime import date, datetime, timedelta
from uuid import UUID

from product_research_ozon.domain.month import (
    CollectionFailure,
    MonthParameters,
    Snapshot,
    SnapshotStatus,
    identity,
)


def month_payload(value: MonthParameters) -> dict[str, object]:
    return {
        "month": value.date_from.strftime("%Y-%m"),
        "date_from": value.date_from.isoformat(),
        "date_to": value.date_to.isoformat(),
        "days": value.days,
        "partial": value.partial,
    }


def identifier(raw: object) -> UUID:
    if not isinstance(raw, str):
        raise TypeError("invalid_identity")
    value = UUID(raw)
    identity(value)
    if str(value) != raw:
        raise ValueError("invalid_identity")
    return value


@dataclass(frozen=True)
class RunSpec:
    company_id: UUID
    run_id: UUID
    project_id: UUID
    source_id: UUID
    category_path: str
    months: tuple[MonthParameters, ...]

    def __post_init__(self) -> None:
        for value in (self.company_id, self.source_id, self.run_id, self.project_id):
            identity(value)
        if (
            not 1 <= len(self.months) <= 24
            or len({p.date_from for p in self.months}) != len(self.months)
            or any(p.category_path != self.category_path for p in self.months)
        ):
            raise ValueError("invalid_run")

    @classmethod
    def from_payload(cls, raw: object, now: datetime) -> RunSpec:
        if not isinstance(raw, dict) or set(raw) != {
            "schema_version",
            "company_id",
            "run_id",
            "project_id",
            "source_connection_id",
            "category_path",
            "fbs",
            "currency",
            "months",
        }:
            raise ValueError("invalid_run")
        if (
            raw["schema_version"] != "1"
            or raw["fbs"] is not True
            or raw["currency"] != "RUB"
            or not isinstance(raw["category_path"], str)
        ):
            raise ValueError("invalid_run")
        months = raw["months"]
        if not isinstance(months, list) or not 1 <= len(months) <= 24:
            raise ValueError("invalid_run")
        values: list[MonthParameters] = []
        seen: set[str] = set()
        for item in months:
            if not isinstance(item, dict) or set(item) != {
                "month",
                "date_from",
                "date_to",
                "days",
                "partial",
            }:
                raise ValueError("invalid_run")
            key, start, end, days, partial = (
                item[name]
                for name in ("month", "date_from", "date_to", "days", "partial")
            )
            if (
                not isinstance(key, str)
                or re.fullmatch(r"20[0-9]{2}-(0[1-9]|1[0-2])", key) is None
                or not isinstance(start, str)
                or not isinstance(end, str)
                or type(days) is not int
                or type(partial) is not bool
                or key in seen
            ):
                raise ValueError("invalid_run")
            value = MonthParameters(
                raw["category_path"],
                date.fromisoformat(start),
                date.fromisoformat(end),
                partial,
            )
            value.validate_frozen(now)
            if start != key + "-01" or days != value.days:
                raise ValueError("invalid_run")
            seen.add(key)
            values.append(value)
        return cls(
            identifier(raw["company_id"]),
            identifier(raw["run_id"]),
            identifier(raw["project_id"]),
            identifier(raw["source_connection_id"]),
            raw["category_path"],
            tuple(values),
        )

    def payload(self) -> dict[str, object]:
        return {
            "schema_version": "1",
            "company_id": str(self.company_id),
            "run_id": str(self.run_id),
            "project_id": str(self.project_id),
            "source_connection_id": str(self.source_id),
            "category_path": self.category_path,
            "fbs": True,
            "currency": "RUB",
            "months": [month_payload(value) for value in self.months],
        }

    @property
    def digest(self) -> str:
        return hashlib.sha256(
            json.dumps(
                self.payload(),
                ensure_ascii=False,
                sort_keys=True,
                separators=(",", ":"),
            ).encode()
        ).hexdigest()


@dataclass(frozen=True)
class JobLease:
    company_id: UUID
    source_id: UUID
    run_id: UUID
    job_id: UUID
    snapshot_id: UUID
    owner: UUID
    generation: int
    deadline: datetime
    network_retries: int
    completeness_retries: int
    cursor: int
    auth_paused: bool = False
    pending_failure: bool = False
    unknown_outcome: bool = False
    pages: int = 0


@dataclass(frozen=True)
class RetryDecision:
    status: str
    next_at: datetime
    network_retries: int
    completeness_retries: int
    restart: bool = False
    error_code: str | None = None


class RetryPolicy:
    @staticmethod
    def decide(
        lease: JobLease,
        snapshot: Snapshot,
        failure: CollectionFailure | None,
        now: datetime,
    ) -> RetryDecision:
        network, complete = lease.network_retries, lease.completeness_retries
        if (
            snapshot.status == SnapshotStatus.READY
            and snapshot.completed_at is not None
            and snapshot.completed_at < lease.deadline
        ):
            return RetryDecision("ready", now, 0, complete)
        if now >= lease.deadline:
            return RetryDecision(
                "error", now, network, complete, error_code="deadline_exceeded"
            )
        if failure is None and snapshot.status == SnapshotStatus.ERROR:
            failure = CollectionFailure(
                snapshot.error_code or "source_unavailable", snapshot.next_attempt_at
            )
        if failure is None:
            return RetryDecision(
                "queued",
                now if snapshot.pages > lease.pages else now + timedelta(seconds=1),
                0 if snapshot.pages > lease.pages else network,
                complete,
            )
        code = failure.code
        if code in ("rate_limited", "report_pending"):
            target = failure.retry_at or now + timedelta(minutes=1)
            if target >= lease.deadline:
                return RetryDecision(
                    "error", now, network, complete, error_code="deadline_exceeded"
                )
            return RetryDecision("queued", max(now, target), network, complete)
        if code in (
            "source_unavailable",
            "storage_unavailable",
            "database_unavailable",
        ):
            if snapshot.pages > lease.pages:
                network = 0
            if network >= 3:
                return RetryDecision(
                    "error", now, network, complete, error_code="retry_exhausted"
                )
            target = max(
                now + timedelta(seconds=60 * (2**network)), failure.retry_at or now
            )
            if target >= lease.deadline:
                return RetryDecision(
                    "error", now, network, complete, error_code="deadline_exceeded"
                )
            return RetryDecision("queued", target, network + 1, complete)
        if code in ("invalid_source", "incomplete", "corrupt_raw"):
            return (
                RetryDecision("queued", now, 0, 1, restart=True)
                if complete == 0
                else RetryDecision(
                    "error", now, network, complete, error_code="incomplete_snapshot"
                )
            )
        if code in ("lease_lost", "obsolete_generation"):
            return RetryDecision(
                "queued", now + timedelta(seconds=1), network, complete
            )
        return RetryDecision(
            "error",
            now,
            network,
            complete,
            error_code="credentials_rejected"
            if code == "authorization_failed"
            else "deadline_exceeded"
            if code == "deadline_exceeded"
            else "contract_violation",
        )
