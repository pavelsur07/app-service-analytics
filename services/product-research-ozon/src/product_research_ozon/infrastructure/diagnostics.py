"""Bounded company/source sample, never a global request/storage counter."""

from dataclasses import dataclass
from datetime import datetime
from uuid import UUID

import psycopg
from psycopg.rows import class_row

from product_research_ozon.domain.month import identity, utc_time


@dataclass(frozen=True)
class JobSample:
    status: str
    origin: str
    error_code: str | None
    created_at: datetime
    deadline: datetime


@dataclass(frozen=True)
class JobReport:
    sampled: int
    truncated: bool
    queued: int
    running: int
    ready: int
    errors: int
    api_ready: int
    cache_ready: int
    authorization_errors: int
    overdue: int
    oldest_active_seconds: int | None


class JobDiagnostics:
    def __init__(self, database_url: str) -> None:
        self._database_url = database_url

    def report(
        self, company_id: UUID, source_id: UUID, now: datetime, limit: int
    ) -> JobReport:
        identity(company_id)
        identity(source_id)
        now = utc_time(now)
        if type(limit) is not int or not 1 <= limit <= 200:
            raise ValueError("invalid_limit")
        with psycopg.connect(
            self._database_url,
            connect_timeout=2,
            options="-c statement_timeout=5000 -c default_transaction_read_only=on",
            row_factory=class_row(JobSample),
        ) as db:
            rows = db.execute(
                """SELECT j.status,j.origin,j.error_code,r.created_at,j.deadline
                FROM research_job j JOIN research_run r
                  ON r.company_id=j.company_id AND r.source_id=j.source_id AND r.run_id=j.run_id
                WHERE j.company_id=%s AND j.source_id=%s
                ORDER BY j.job_id DESC LIMIT %s""",
                (company_id, source_id, limit + 1),
            ).fetchall()
        sample = rows[:limit]
        active = [row for row in sample if row.status in ("queued", "running")]
        return JobReport(
            sampled=len(sample),
            truncated=len(rows) > limit,
            queued=sum(row.status == "queued" for row in sample),
            running=sum(row.status == "running" for row in sample),
            ready=sum(row.status == "ready" for row in sample),
            errors=sum(row.status == "error" for row in sample),
            api_ready=sum(
                row.status == "ready" and row.origin == "api" for row in sample
            ),
            cache_ready=sum(
                row.status == "ready" and row.origin == "cache" for row in sample
            ),
            authorization_errors=sum(
                row.error_code == "credentials_rejected" for row in sample
            ),
            overdue=sum(row.deadline <= now for row in active),
            oldest_active_seconds=max(
                (max(0, int((now - row.created_at).total_seconds())) for row in active),
                default=None,
            ),
        )
