from datetime import datetime, timedelta
from uuid import UUID

import psycopg

from product_research_ozon.domain.catalog import (
    MAX_CATEGORIES,
    TTL,
    CatalogUnavailable,
    CatalogVersion,
    Category,
)


class CatalogStore:
    def __init__(self, database_url: str) -> None:
        self._url = database_url

    def _connect(self) -> psycopg.Connection[tuple[object, ...]]:
        return psycopg.connect(
            self._url,
            connect_timeout=2,
            options="-c statement_timeout=5000 -c lock_timeout=1000",
        )

    def metadata(self, company_id: UUID, source_id: UUID) -> CatalogVersion | None:
        with self._connect() as db:
            row = db.execute(
                "SELECT revision, generated_at FROM catalog_state WHERE company_id=%s AND source_id=%s AND revision>0 LIMIT 1",
                (company_id, source_id),
            ).fetchone()
        return None if row is None else self._version(row)

    def claim(
        self, company_id: UUID, source_id: UUID, owner: UUID, now: datetime
    ) -> bool:
        with self._connect() as db:
            row = db.execute(
                """INSERT INTO catalog_state (company_id,source_id,lease_id,lease_until)
                VALUES (%s,%s,%s,clock_timestamp()+interval '60 seconds')
                ON CONFLICT (company_id,source_id) DO UPDATE
                SET lease_id=EXCLUDED.lease_id,lease_until=EXCLUDED.lease_until,error_code=NULL
                WHERE (catalog_state.lease_until IS NULL OR catalog_state.lease_until<=clock_timestamp())
                  AND (catalog_state.next_attempt_at IS NULL OR catalog_state.next_attempt_at<=%s)
                  AND (catalog_state.generated_at IS NULL OR catalog_state.generated_at<=%s OR catalog_state.generated_at>%s)
                RETURNING lease_id""",
                (company_id, source_id, owner, now, now - TTL, now),
            ).fetchone()
        return row is not None

    def activate_generation(
        self, source_id: UUID, generation: int, now: datetime
    ) -> bool:
        """Startup fence: only a strictly newer generation can replace active credentials."""
        with self._connect() as db:
            row = db.execute(
                """INSERT INTO catalog_provider_gate (source_id,active_generation,next_request_at)
                VALUES (%s,%s,%s) ON CONFLICT (source_id) DO UPDATE
                SET active_generation=EXCLUDED.active_generation,paused_generation=NULL
                WHERE catalog_provider_gate.active_generation<EXCLUDED.active_generation
                RETURNING active_generation""",
                (source_id, generation, now),
            ).fetchone()
            if row is None:
                row = db.execute(
                    "SELECT active_generation FROM catalog_provider_gate WHERE source_id=%s LIMIT 1",
                    (source_id,),
                ).fetchone()
        return row is not None and row[0] == generation

    def generation_current(self, source_id: UUID, generation: int) -> bool:
        with self._connect() as db:
            return (
                db.execute(
                    "SELECT 1 FROM catalog_provider_gate WHERE source_id=%s AND active_generation=%s LIMIT 1",
                    (source_id, generation),
                ).fetchone()
                is not None
            )

    def provider_claim(
        self, source_id: UUID, owner: UUID, generation: int, rpm: int, now: datetime
    ) -> bool:
        with self._connect() as db:
            row = db.execute(
                """UPDATE catalog_provider_gate SET lease_id=%s,
                lease_until=clock_timestamp()+interval '60 seconds',next_request_at=%s
                WHERE source_id=%s AND active_generation=%s
                  AND (lease_until IS NULL OR lease_until<=clock_timestamp())
                  AND next_request_at<=%s AND paused_generation IS DISTINCT FROM %s
                RETURNING lease_id""",
                (
                    owner,
                    now + timedelta(microseconds=(60_000_000 + rpm - 1) // rpm),
                    source_id,
                    generation,
                    now,
                    generation,
                ),
            ).fetchone()
        return row is not None

    def provider_status(
        self, source_id: UUID, generation: int
    ) -> tuple[str, datetime | None]:
        """Shared server credential scope (ADR-050), never company data."""
        with self._connect() as db:
            row = db.execute(
                """SELECT active_generation, paused_generation,
                GREATEST(next_request_at, COALESCE(lease_until,clock_timestamp()),clock_timestamp()+interval '1 second')
                FROM catalog_provider_gate WHERE source_id=%s LIMIT 1""",
                (source_id,),
            ).fetchone()
        if row is None or row[0] != generation:
            return "obsolete_generation", None
        if row[1] == generation:
            return "authorization_failed", None
        if not isinstance(row[2], datetime):
            return "source_unavailable", None
        return "rate_limited", row[2]

    def provider_release(
        self,
        source_id: UUID,
        owner: UUID,
        generation: int,
        failure: CatalogUnavailable | None,
    ) -> None:
        with self._connect() as db:
            db.execute(
                """UPDATE catalog_provider_gate SET lease_id=NULL,lease_until=NULL,
                next_request_at=GREATEST(next_request_at,COALESCE(%s,next_request_at)),
                paused_generation=CASE WHEN %s AND active_generation=%s THEN %s ELSE paused_generation END
                WHERE source_id=%s AND lease_id=%s""",
                (
                    failure.retry_at if failure else None,
                    failure is not None and failure.code == "authorization_failed",
                    generation,
                    generation,
                    source_id,
                    owner,
                ),
            )

    def fail(
        self,
        company_id: UUID,
        source_id: UUID,
        owner: UUID,
        failure: CatalogUnavailable,
        now: datetime,
    ) -> None:
        with self._connect() as db:
            db.execute(
                """UPDATE catalog_state SET lease_id=NULL,lease_until=NULL,next_attempt_at=%s,error_code=%s
                WHERE company_id=%s AND source_id=%s AND lease_id=%s""",
                (
                    failure.retry_at or now + timedelta(minutes=1),
                    failure.code,
                    company_id,
                    source_id,
                    owner,
                ),
            )

    def publish(
        self,
        company_id: UUID,
        source_id: UUID,
        owner: UUID,
        categories: tuple[Category, ...],
        generated_at: datetime,
        generation: int,
    ) -> CatalogVersion:
        if len(categories) > MAX_CATEGORIES or len(
            {entry.key for entry in categories}
        ) != len(categories):
            raise CatalogUnavailable("invalid_source")
        with self._connect() as db:
            row = db.execute(
                """SELECT revision FROM catalog_state WHERE company_id=%s AND source_id=%s
                AND lease_id=%s AND lease_until>clock_timestamp() FOR UPDATE""",
                (company_id, source_id, owner),
            ).fetchone()
            if row is None or not isinstance(row[0], int):
                raise CatalogUnavailable("lease_lost")
            gate = db.execute(
                "SELECT active_generation FROM catalog_provider_gate WHERE source_id=%s FOR SHARE",
                (source_id,),
            ).fetchone()
            if gate is None or gate[0] != generation:
                raise CatalogUnavailable("obsolete_generation")
            revision = row[0] + 1
            db.execute(
                "DELETE FROM catalog_entry WHERE company_id=%s AND source_id=%s",
                (company_id, source_id),
            )
            with db.cursor().copy(
                "COPY catalog_entry (company_id,source_id,path_key,path,provider_id) FROM STDIN"
            ) as copy:
                for entry in categories:
                    copy.write_row(
                        (company_id, source_id, entry.key, entry.path, entry.source_id)
                    )
            updated = db.execute(
                """UPDATE catalog_state SET revision=%s,generated_at=%s,lease_id=NULL,lease_until=NULL,
                next_attempt_at=NULL,error_code=NULL WHERE company_id=%s AND source_id=%s
                AND lease_id=%s AND lease_until>clock_timestamp() RETURNING revision""",
                (revision, generated_at, company_id, source_id, owner),
            ).fetchone()
            if updated is None:
                raise CatalogUnavailable("lease_lost")
        return CatalogVersion(revision, generated_at)

    def page(
        self,
        company_id: UUID,
        source_id: UUID,
        revision: int,
        after: str,
        limit: int,
        now: datetime,
    ) -> tuple[Category, ...]:
        if not 1 <= limit <= 100:
            raise CatalogUnavailable("contract_violation")
        # Header and rows in one MVCC statement, including a genuinely empty snapshot.
        with self._connect() as db:
            rows = db.execute(
                """SELECT s.revision,s.generated_at,e.path,e.provider_id
                FROM catalog_state s LEFT JOIN catalog_entry e ON e.company_id=s.company_id
                    AND e.source_id=s.source_id AND e.path_key>%s
                WHERE s.company_id=%s AND s.source_id=%s AND s.revision>0
                ORDER BY e.path_key LIMIT %s""",
                (after, company_id, source_id, limit + 1),
            ).fetchall()
        if (
            not rows
            or self._version(rows[0]).revision != revision
            or not self._version(rows[0]).is_fresh(now)
        ):
            raise CatalogUnavailable("conflict")
        result: list[Category] = []
        for row in rows:
            if row[2] is None:
                continue
            if (
                not isinstance(row[2], str)
                or row[3] is not None
                and not isinstance(row[3], str)
            ):
                raise CatalogUnavailable("invalid_cache")
            result.append(Category(row[2], row[3]))
        return tuple(result)

    @staticmethod
    def _version(row: tuple[object, ...]) -> CatalogVersion:
        if not isinstance(row[0], int) or not isinstance(row[1], datetime):
            raise CatalogUnavailable("invalid_cache")
        return CatalogVersion(row[0], row[1])
