"""Frozen monthly inputs and source completeness; no infrastructure or money arithmetic."""

import calendar
import hashlib
import json
from dataclasses import dataclass
from datetime import UTC, date, datetime, timedelta
from decimal import Decimal, DecimalException
from enum import StrEnum
from typing import Never
from uuid import UUID
from zoneinfo import ZoneInfo

from product_research_ozon.domain.catalog import Category

PAGE_SIZE = 500
MAX_PAGE_BYTES = 16 * 1024 * 1024


class CollectionFailure(Exception):
    def __init__(self, code: str, retry_at: datetime | None = None) -> None:
        allowed = {
            "invalid_source",
            "incomplete",
            "budget_exceeded",
            "authorization_failed",
            "rate_limited",
            "report_pending",
            "source_unavailable",
            "conflict",
            "not_found",
            "lease_lost",
            "obsolete_generation",
            "storage_unavailable",
            "corrupt_raw",
            "database_unavailable",
            "deadline_exceeded",
            "contract_violation",
            "not_available",
        }
        self.code = code if code in allowed else "source_unavailable"
        self.retry_at = retry_at
        super().__init__(self.code)


def identity(value: UUID) -> None:
    if not isinstance(value, UUID) or value.version != 7:
        raise ValueError("invalid_identity")


def utc_time(value: datetime) -> datetime:
    if value.tzinfo is None:
        raise ValueError("invalid_event_time")
    return value.astimezone(UTC)


@dataclass(frozen=True)
class CollectionLimits:
    max_rows: int
    max_pages: int
    max_bytes: int

    def __post_init__(self) -> None:
        if (
            any(
                type(v) is not int or v <= 0
                for v in (self.max_rows, self.max_pages, self.max_bytes)
            )
            or self.max_pages > 1000000
            or self.max_rows > 2147483647
            or self.max_bytes > 9223372036854775807
        ):
            raise ValueError("invalid_collection_budget")


@dataclass(frozen=True)
class MonthParameters:
    category_path: str
    date_from: date
    date_to: date
    partial: bool

    def __post_init__(self) -> None:
        Category(self.category_path)
        if (
            type(self.partial) is not bool
            or type(self.date_from) is not date
            or type(self.date_to) is not date
            or self.date_from.day != 1
            or self.date_to < self.date_from
            or (self.date_from.year, self.date_from.month)
            != (self.date_to.year, self.date_to.month)
            or (
                self.date_to.day
                == calendar.monthrange(self.date_from.year, self.date_from.month)[1]
            )
            == self.partial
        ):
            raise ValueError("invalid_month")

    @property
    def days(self) -> int:
        return (self.date_to - self.date_from).days + 1

    @classmethod
    def create(
        cls,
        path: str,
        year: int,
        month: int,
        now: datetime,
        allow_partial: bool = False,
    ) -> MonthParameters:
        if now.tzinfo is None or type(allow_partial) is not bool:
            raise ValueError("invalid_month")
        today = now.astimezone(ZoneInfo("Europe/Moscow")).date()
        start = date(year, month, 1)
        if start > today.replace(day=1):
            raise ValueError("future_month")
        partial = start == today.replace(day=1)
        if partial and (not allow_partial or today.day == 1):
            raise ValueError("partial_month_not_allowed")
        end = (
            today - timedelta(days=1)
            if partial
            else start.replace(day=calendar.monthrange(year, month)[1])
        )
        return cls(path, start, end, partial)

    def validate_at(self, now: datetime) -> None:
        if self != self.create(
            self.category_path,
            self.date_from.year,
            self.date_from.month,
            now,
            self.partial,
        ):
            raise ValueError("invalid_month_dates")

    def validate_frozen(self, now: datetime) -> None:
        # PHP fixes dates before delivery; a delayed partial slice must not expand.
        today = utc_time(now).astimezone(ZoneInfo("Europe/Moscow")).date()
        if self.date_to >= today:
            raise ValueError("future_month")


@dataclass(frozen=True)
class SourcePage:
    body: bytes
    start: int
    end: int
    total: int
    ids: tuple[int, ...]

    @property
    def sha256(self) -> str:
        return hashlib.sha256(self.body).hexdigest()


def _constant(value: str) -> Never:
    raise ValueError("invalid_json")


def _object(pairs: list[tuple[str, object]]) -> dict[str, object]:
    result: dict[str, object] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate_json_key")
        result[key] = value
    return result


def parse_page(body: bytes, start: int, end: int) -> SourcePage:
    if not 0 <= start < end or end - start > PAGE_SIZE or len(body) > MAX_PAGE_BYTES:
        raise CollectionFailure("invalid_source")
    try:
        raw: object = json.loads(
            body,
            parse_float=Decimal,
            parse_constant=_constant,
            object_pairs_hook=_object,
        )
        if not isinstance(raw, dict):
            raise TypeError
        counts = [raw.get(name) for name in ("startRow", "endRow", "total")]
        if any(
            type(value) is not int or not 0 <= value <= 2147483647 for value in counts
        ):
            raise ValueError
        actual_start, actual_end, total = counts
        if (
            not isinstance(actual_start, int)
            or not isinstance(actual_end, int)
            or not isinstance(total, int)
        ):
            raise TypeError
        rows = raw.get("data")
        if not isinstance(rows, list) or actual_start != start or start > total:
            raise ValueError
        if actual_end != start + len(rows) or actual_end != min(end, total):
            raise ValueError
        if len(rows) != min(end - start, total - start) or (
            not rows and (start != 0 or total != 0)
        ):
            raise ValueError
        ids: list[int] = []
        for row in rows:
            if not isinstance(row, dict):
                raise TypeError
            sku = row.get("id")
            if type(sku) is not int or not 0 < sku <= 9223372036854775807:
                raise ValueError
            ids.append(sku)
        if len(ids) != len(set(ids)):
            raise ValueError
        return SourcePage(body, start, actual_end, total, tuple(ids))
    except ValueError, TypeError, UnicodeError, RecursionError, DecimalException:
        raise CollectionFailure("invalid_source") from None


class SnapshotStatus(StrEnum):
    COLLECTING = "collecting"
    READY = "ready"
    ERROR = "error"


@dataclass(frozen=True)
class Snapshot:
    company_id: UUID
    source_id: UUID
    snapshot_id: UUID
    parameters: MonthParameters
    limits: CollectionLimits
    generation: int
    status: SnapshotStatus
    created_at: datetime
    completed_at: datetime | None
    total: int | None
    cursor: int
    pages: int
    byte_size: int
    next_attempt_at: datetime | None
    error_code: str | None
    manifest_sha256: str | None
    manifest_size: int | None
    job_id: UUID | None = None
    outcome_revision: int = 0


@dataclass(frozen=True)
class StoredPage:
    page_no: int
    start: int
    end: int
    total: int
    sha256: str
    byte_size: int
