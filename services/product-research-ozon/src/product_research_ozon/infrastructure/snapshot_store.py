"""Technical journal only; page bodies and monetary values live exclusively in S3."""

from collections.abc import Iterator
from contextlib import contextmanager
from datetime import date, datetime
from uuid import UUID

import psycopg

from product_research_ozon.domain.month import (
    CollectionFailure,
    CollectionLimits,
    MonthParameters,
    Snapshot,
    SnapshotStatus,
    SourcePage,
    StoredPage,
    identity,
)

COLUMNS = "company_id,source_id,snapshot_id,category_path,date_from,date_to,partial,max_rows,max_pages,max_bytes,generation,status,created_at,completed_at,total,cursor_row,pages,byte_size,next_attempt_at,error_code,manifest_sha256,manifest_size"


def typed[T](value: object, kind: type[T]) -> T:
    if not isinstance(value, kind):
        raise CollectionFailure("conflict")
    return value


def optional[T](value: object, kind: type[T]) -> T | None:
    return None if value is None else typed(value, kind)


def snapshot(row: tuple[object, ...]) -> Snapshot:
    return Snapshot(
        typed(row[0], UUID),
        typed(row[1], UUID),
        typed(row[2], UUID),
        MonthParameters(
            typed(row[3], str),
            typed(row[4], date),
            typed(row[5], date),
            typed(row[6], bool),
        ),
        CollectionLimits(typed(row[7], int), typed(row[8], int), typed(row[9], int)),
        typed(row[10], int),
        SnapshotStatus(typed(row[11], str)),
        typed(row[12], datetime),
        optional(row[13], datetime),
        optional(row[14], int),
        typed(row[15], int),
        typed(row[16], int),
        typed(row[17], int),
        optional(row[18], datetime),
        optional(row[19], str),
        optional(row[20], str),
        optional(row[21], int),
    )


class SnapshotStore:
    def __init__(self, database_url: str) -> None:
        self._url = database_url

    @contextmanager
    def _db(self) -> Iterator[psycopg.Connection[tuple[object, ...]]]:
        try:
            with psycopg.connect(
                self._url,
                connect_timeout=2,
                options="-c statement_timeout=5000 -c lock_timeout=1000",
            ) as db:
                yield db
        except psycopg.Error:
            raise CollectionFailure("database_unavailable") from None

    @staticmethod
    def _scope(
        company_id: UUID, source_id: UUID, snapshot_id: UUID
    ) -> tuple[UUID, UUID, UUID]:
        for value in (company_id, source_id, snapshot_id):
            identity(value)
        return company_id, source_id, snapshot_id

    def create(
        self,
        company_id: UUID,
        source_id: UUID,
        snapshot_id: UUID,
        parameters: MonthParameters,
        limits: CollectionLimits,
        generation: int,
        now: datetime,
    ) -> Snapshot:
        key = self._scope(company_id, source_id, snapshot_id)
        parameters.validate_at(now)
        with self._db() as db:
            result = db.execute(
                """INSERT INTO month_snapshot (company_id,source_id,snapshot_id,category_path,date_from,date_to,partial,max_rows,max_pages,max_bytes,generation,created_at)
                SELECT %s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s
                FROM catalog_provider_gate WHERE source_id=%s AND active_generation=%s
                ON CONFLICT (company_id,source_id,snapshot_id) DO NOTHING RETURNING snapshot_id""",
                (
                    *key,
                    parameters.category_path,
                    parameters.date_from,
                    parameters.date_to,
                    parameters.partial,
                    limits.max_rows,
                    limits.max_pages,
                    limits.max_bytes,
                    generation,
                    now,
                    source_id,
                    generation,
                ),
            ).fetchone()
            row = db.execute(
                f"SELECT {COLUMNS} FROM month_snapshot WHERE company_id=%s AND source_id=%s AND snapshot_id=%s LIMIT 1",
                key,
            ).fetchone()
            if row is None:
                raise CollectionFailure("obsolete_generation")
            existing = snapshot(row)
            if result is None and (
                existing.parameters != parameters
                or existing.limits != limits
                or existing.generation != generation
            ):
                raise CollectionFailure("conflict")
        return existing

    def get(self, company_id: UUID, source_id: UUID, snapshot_id: UUID) -> Snapshot:
        key = self._scope(company_id, source_id, snapshot_id)
        with self._db() as db:
            row = db.execute(
                f"SELECT {COLUMNS} FROM month_snapshot WHERE company_id=%s AND source_id=%s AND snapshot_id=%s LIMIT 1",
                key,
            ).fetchone()
        if row is None:
            raise CollectionFailure("not_found")
        return snapshot(row)

    def claim(
        self,
        company_id: UUID,
        source_id: UUID,
        snapshot_id: UUID,
        owner: UUID,
        generation: int,
        now: datetime,
    ) -> bool:
        key = self._scope(company_id, source_id, snapshot_id)
        identity(owner)
        with self._db() as db:
            row = db.execute(
                """UPDATE month_snapshot SET lease_id=%s,lease_until=clock_timestamp()+interval '120 seconds'
                WHERE company_id=%s AND source_id=%s AND snapshot_id=%s AND generation=%s AND status='collecting'
                AND (lease_until IS NULL OR lease_until<=clock_timestamp())
                AND (next_attempt_at IS NULL OR next_attempt_at<=%s) RETURNING snapshot_id""",
                (owner, *key, generation, now),
            ).fetchone()
        return row is not None

    def _owned(
        self,
        db: psycopg.Connection[tuple[object, ...]],
        key: tuple[UUID, UUID, UUID],
        owner: UUID,
    ) -> Snapshot:
        row = db.execute(
            f"SELECT {COLUMNS} FROM month_snapshot WHERE company_id=%s AND source_id=%s AND snapshot_id=%s AND status='collecting' AND lease_id=%s AND lease_until>clock_timestamp() FOR UPDATE",
            (*key, owner),
        ).fetchone()
        if row is None:
            raise CollectionFailure("lease_lost")
        value = snapshot(row)
        gate = db.execute(
            "SELECT active_generation FROM catalog_provider_gate WHERE source_id=%s FOR SHARE",
            (value.source_id,),
        ).fetchone()
        if gate is None or gate[0] != value.generation:
            raise CollectionFailure("obsolete_generation")
        return value

    def append(
        self,
        company_id: UUID,
        source_id: UUID,
        snapshot_id: UUID,
        owner: UUID,
        page: SourcePage,
    ) -> Snapshot:
        key = self._scope(company_id, source_id, snapshot_id)
        with self._db() as db:
            value = self._owned(db, key, owner)
            if (
                page.start != value.cursor
                or value.total is not None
                and page.total != value.total
            ):
                raise CollectionFailure("incomplete")
            if (
                page.total > value.limits.max_rows
                or value.pages + 1 > value.limits.max_pages
                or value.byte_size + len(page.body) > value.limits.max_bytes
            ):
                raise CollectionFailure("budget_exceeded")
            db.execute(
                "CREATE TEMP TABLE page_skus (sku bigint PRIMARY KEY) ON COMMIT DROP"
            )
            with db.cursor().copy("COPY page_skus (sku) FROM STDIN") as copy:
                for sku in page.ids:
                    copy.write_row((sku,))
            inserted = db.execute(
                """INSERT INTO snapshot_sku_check(company_id,source_id,snapshot_id,sku)
                SELECT %s,%s,%s,sku FROM page_skus ON CONFLICT DO NOTHING RETURNING sku""",
                key,
            ).fetchall()
            if len(inserted) != len(page.ids):
                raise CollectionFailure("incomplete")
            db.execute(
                "INSERT INTO snapshot_page(company_id,source_id,snapshot_id,page_no,start_row,end_row,total,sha256,byte_size) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                (
                    *key,
                    value.pages,
                    page.start,
                    page.end,
                    page.total,
                    page.sha256,
                    len(page.body),
                ),
            )
            row = db.execute(
                f"""UPDATE month_snapshot SET total=%s,cursor_row=%s,pages=pages+1,byte_size=byte_size+%s,next_attempt_at=NULL,error_code=NULL
                WHERE company_id=%s AND source_id=%s AND snapshot_id=%s AND lease_id=%s AND lease_until>clock_timestamp() RETURNING {COLUMNS}""",
                (page.total, page.end, len(page.body), *key, owner),
            ).fetchone()
            if row is None:
                raise CollectionFailure("lease_lost")
        return snapshot(row)

    def pages(
        self, company_id: UUID, source_id: UUID, snapshot_id: UUID
    ) -> tuple[StoredPage, ...]:
        key = self._scope(company_id, source_id, snapshot_id)
        value = self.get(*key)
        with self._db() as db:
            rows = db.execute(
                "SELECT page_no,start_row,end_row,total,sha256,byte_size FROM snapshot_page WHERE company_id=%s AND source_id=%s AND snapshot_id=%s ORDER BY page_no LIMIT %s",
                (*key, value.limits.max_pages),
            ).fetchall()
        return tuple(
            StoredPage(
                typed(r[0], int),
                typed(r[1], int),
                typed(r[2], int),
                typed(r[3], int),
                typed(r[4], str),
                typed(r[5], int),
            )
            for r in rows
        )

    def page(
        self, company_id: UUID, source_id: UUID, snapshot_id: UUID, page_no: int
    ) -> StoredPage:
        key = self._scope(company_id, source_id, snapshot_id)
        if type(page_no) is not int or not 0 <= page_no < 1000000:
            raise CollectionFailure("not_found")
        with self._db() as db:
            row = db.execute(
                "SELECT page_no,start_row,end_row,total,sha256,byte_size FROM snapshot_page WHERE company_id=%s AND source_id=%s AND snapshot_id=%s AND page_no=%s LIMIT 1",
                (*key, page_no),
            ).fetchone()
        if row is None:
            raise CollectionFailure("not_found")
        return StoredPage(
            typed(row[0], int),
            typed(row[1], int),
            typed(row[2], int),
            typed(row[3], int),
            typed(row[4], str),
            typed(row[5], int),
        )

    def finish(
        self,
        company_id: UUID,
        source_id: UUID,
        snapshot_id: UUID,
        owner: UUID,
        sha256: str,
        size: int,
        now: datetime,
    ) -> Snapshot:
        key = self._scope(company_id, source_id, snapshot_id)
        with self._db() as db:
            value = self._owned(db, key, owner)
            if value.total is None or value.total != value.cursor or value.pages == 0:
                raise CollectionFailure("incomplete")
            row = db.execute(
                f"""UPDATE month_snapshot SET status='ready',completed_at=%s,manifest_sha256=%s,manifest_size=%s,
                lease_id=NULL,lease_until=NULL,next_attempt_at=NULL,error_code=NULL
                WHERE company_id=%s AND source_id=%s AND snapshot_id=%s AND lease_id=%s AND lease_until>clock_timestamp()
                RETURNING {COLUMNS}""",
                (now, sha256, size, *key, owner),
            ).fetchone()
            if row is None:
                raise CollectionFailure("lease_lost")
            db.execute(
                "DELETE FROM snapshot_sku_check WHERE company_id=%s AND source_id=%s AND snapshot_id=%s",
                key,
            )
        return snapshot(row)

    def release(
        self,
        company_id: UUID,
        source_id: UUID,
        snapshot_id: UUID,
        owner: UUID,
        failure: CollectionFailure | None = None,
    ) -> None:
        key = self._scope(company_id, source_id, snapshot_id)
        deferred = failure is not None and failure.code in (
            "rate_limited",
            "report_pending",
        )
        with self._db() as db:
            row = db.execute(
                """UPDATE month_snapshot SET lease_id=NULL,lease_until=NULL,status=%s,next_attempt_at=%s,error_code=%s
                WHERE company_id=%s AND source_id=%s AND snapshot_id=%s AND status='collecting' AND lease_id=%s AND lease_until>clock_timestamp()
                RETURNING snapshot_id""",
                (
                    "collecting" if failure is None or deferred else "error",
                    failure.retry_at if failure else None,
                    failure.code if failure else None,
                    *key,
                    owner,
                ),
            ).fetchone()
            if row is not None and failure is not None and not deferred:
                db.execute(
                    "DELETE FROM snapshot_sku_check WHERE company_id=%s AND source_id=%s AND snapshot_id=%s",
                    key,
                )
