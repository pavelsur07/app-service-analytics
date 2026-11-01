"""Technical queue. Cross-company selection is confined to the explicitly named worker method (ADR-050)."""

from collections.abc import Iterator
from contextlib import contextmanager
from datetime import date, datetime, timedelta
from uuid import UUID, uuid7

import psycopg
from psycopg.types.json import Jsonb

from product_research_ozon.domain.jobs import (
    JobLease,
    RetryDecision,
    RunSpec,
    month_payload,
)
from product_research_ozon.domain.month import (
    CollectionFailure,
    CollectionLimits,
    MonthParameters,
    Snapshot,
    utc_time,
)
from product_research_ozon.infrastructure.snapshot_store import COLUMNS, snapshot, typed


class JobStore:
    def __init__(self, database_url: str) -> None:
        self._url = database_url

    @contextmanager
    def _db(self) -> Iterator[psycopg.Connection[tuple[object, ...]]]:
        try:
            with psycopg.connect(
                self._url,
                connect_timeout=2,
                options="-c statement_timeout=5000 -c lock_timeout=1000 -c timezone=UTC",
            ) as db:
                yield db
        except psycopg.Error:
            raise CollectionFailure("database_unavailable") from None

    @staticmethod
    def _insert_snapshots(
        db: psycopg.Connection[tuple[object, ...]],
        company_id: UUID,
        source_id: UUID,
        path: str,
        seeds: list[dict[str, object]],
        limits: CollectionLimits,
        generation: int,
        now: datetime,
    ) -> None:
        db.execute(
            """INSERT INTO month_snapshot (company_id,source_id,snapshot_id,category_path,date_from,date_to,partial,max_rows,max_pages,max_bytes,generation,created_at,job_id)
            SELECT %s,%s,x.snapshot_id,%s,x.date_from,x.date_to,x.partial,%s,%s,%s,%s,%s,x.job_id
            FROM jsonb_to_recordset(%s::jsonb) AS x(snapshot_id uuid,job_id uuid,date_from date,date_to date,partial boolean)""",
            (
                company_id,
                source_id,
                path,
                limits.max_rows,
                limits.max_pages,
                limits.max_bytes,
                generation,
                now,
                Jsonb(seeds),
            ),
        )

    def accept(
        self,
        company_id: UUID,
        spec: RunSpec,
        limits: CollectionLimits,
        generation: int,
        now: datetime,
    ) -> dict[str, object]:
        if company_id != spec.company_id:
            raise CollectionFailure("conflict")
        with self._db() as db:
            gate = db.execute(
                "SELECT active_generation FROM catalog_provider_gate WHERE source_id=%s FOR SHARE",
                (spec.source_id,),
            ).fetchone()
            if gate is None or gate[0] != generation:
                raise CollectionFailure("obsolete_generation")
            inserted = db.execute(
                """INSERT INTO research_run(company_id,run_id,source_id,project_id,request_hash,request_payload,created_at)
                VALUES (%s,%s,%s,%s,%s,%s,%s) ON CONFLICT(company_id,run_id) DO NOTHING RETURNING run_id""",
                (
                    company_id,
                    spec.run_id,
                    spec.source_id,
                    spec.project_id,
                    spec.digest,
                    Jsonb(spec.payload()),
                    now,
                ),
            ).fetchone()
            if inserted is None:
                old = db.execute(
                    "SELECT request_hash FROM research_run WHERE company_id=%s AND run_id=%s LIMIT 1",
                    (company_id, spec.run_id),
                ).fetchone()
                if old is None or old[0] != spec.digest:
                    raise CollectionFailure("conflict")
            else:
                seeds: list[dict[str, object]] = []
                job_rows: list[tuple[object, ...]] = []
                for period in spec.months:
                    job_id, snapshot_id = uuid7(), uuid7()
                    seeds.append(
                        {
                            "job_id": str(job_id),
                            "snapshot_id": str(snapshot_id),
                            "date_from": period.date_from.isoformat(),
                            "date_to": period.date_to.isoformat(),
                            "partial": period.partial,
                        }
                    )
                    job_rows.append(
                        (
                            company_id,
                            spec.source_id,
                            spec.run_id,
                            job_id,
                            period.date_from.strftime("%Y-%m"),
                            snapshot_id,
                            now + timedelta(hours=24),
                            now,
                            now,
                        )
                    )
                with db.cursor().copy(
                    "COPY research_job(company_id,source_id,run_id,job_id,month,snapshot_id,deadline,next_attempt_at,last_claimed_at) FROM STDIN"
                ) as copy:
                    for row in job_rows:
                        copy.write_row(row)
                self._insert_snapshots(
                    db,
                    company_id,
                    spec.source_id,
                    spec.category_path,
                    seeds,
                    limits,
                    generation,
                    now,
                )
                db.execute(
                    "INSERT INTO research_dispatch_turn(company_id,source_id) VALUES (%s,%s) ON CONFLICT DO NOTHING",
                    (company_id, spec.source_id),
                )
        return {
            "schema_version": "1",
            "company_id": str(company_id),
            "run_id": str(spec.run_id),
            "status": "queued",
            "revision": 1,
        }

    def generation_current(self, source_id: UUID, generation: int) -> bool:
        with self._db() as db:
            return (
                db.execute(
                    "SELECT 1 FROM catalog_provider_gate WHERE source_id=%s AND active_generation=%s LIMIT 1",
                    (source_id, generation),
                ).fetchone()
                is not None
            )

    def claim_next_for_all_companies(
        self, source_id: UUID, generation: int, allowed: tuple[UUID, ...], now: datetime
    ) -> JobLease | None:
        """Operational inter-tenant dispatch, ADR-050/CLAUDE §1; inaccessible to seller handlers."""
        if not allowed:
            return None
        with self._db() as db:
            gate = db.execute(
                "SELECT active_generation,paused_generation FROM catalog_provider_gate WHERE source_id=%s FOR SHARE",
                (source_id,),
            ).fetchone()
            if gate is None or gate[0] != generation:
                return None
            paused = gate[1] == generation
            turn = db.execute(
                """SELECT t.company_id FROM research_dispatch_turn t
                WHERE t.source_id=%s AND t.company_id=ANY(%s::uuid[]) AND EXISTS(
                  SELECT 1 FROM research_job j WHERE j.company_id=t.company_id AND j.source_id=t.source_id
                  AND j.status IN ('queued','running') AND (j.lease_until IS NULL OR j.lease_until<=clock_timestamp())
                  AND (j.next_attempt_at<=%s OR j.deadline<=%s OR %s))
                ORDER BY t.last_served_at,t.company_id FOR UPDATE SKIP LOCKED LIMIT 1""",
                (source_id, list(allowed), now, now, paused),
            ).fetchone()
            if turn is None:
                return None
            company = typed(turn[0], UUID)
            candidate = db.execute(
                """SELECT run_id,job_id FROM research_job WHERE company_id=%s AND source_id=%s
                AND status IN ('queued','running') AND (lease_until IS NULL OR lease_until<=clock_timestamp())
                AND (next_attempt_at<=%s OR deadline<=%s OR %s) ORDER BY last_claimed_at,month,job_id LIMIT 1""",
                (company, source_id, now, now, paused),
            ).fetchone()
            if candidate is None:
                return None
            run_id, job_id = typed(candidate[0], UUID), typed(candidate[1], UUID)
            db.execute(
                "SELECT run_id FROM research_run WHERE company_id=%s AND run_id=%s FOR UPDATE",
                (company, run_id),
            ).fetchone()
            row = db.execute(
                """SELECT snapshot_id,deadline,network_retries,completeness_retries,retry_cursor FROM research_job
                WHERE company_id=%s AND source_id=%s AND job_id=%s AND status IN ('queued','running')
                AND (lease_until IS NULL OR lease_until<=clock_timestamp()) FOR UPDATE""",
                (company, source_id, job_id),
            ).fetchone()
            if row is None:
                return None
            snapshot_id = typed(row[0], UUID)
            snap_row = db.execute(
                f"SELECT {COLUMNS} FROM month_snapshot WHERE company_id=%s AND source_id=%s AND snapshot_id=%s FOR UPDATE",
                (company, source_id, snapshot_id),
            ).fetchone()
            if snap_row is None:
                raise CollectionFailure("conflict")
            snap = snapshot(snap_row)
            restarted = snap.generation != generation and snap.status != "ready"
            if restarted:
                snapshot_id = self._restart(
                    db, company, source_id, job_id, snap, generation, now
                )
                snap = self._load_snapshot(db, company, source_id, snapshot_id)
            # retry_cursor records the page at the last claim. An append may commit
            # before job ack; detecting that advancement here restores the new page budget.
            network = (
                0
                if restarted or snap.cursor > typed(row[4], int)
                else typed(row[2], int)
            )
            owner = uuid7()
            db.execute(
                """UPDATE research_job SET status='running',lease_id=%s,lease_until=clock_timestamp()+interval '180 seconds',last_claimed_at=clock_timestamp(),network_retries=%s,retry_cursor=%s
                WHERE company_id=%s AND job_id=%s""",
                (owner, network, snap.cursor, company, job_id),
            )
            db.execute(
                "UPDATE research_dispatch_turn SET last_served_at=clock_timestamp() WHERE company_id=%s AND source_id=%s",
                (company, source_id),
            )
            db.execute(
                "UPDATE research_run SET status='running',revision=revision+1 WHERE company_id=%s AND run_id=%s",
                (company, run_id),
            )
            return JobLease(
                company,
                source_id,
                run_id,
                job_id,
                snapshot_id,
                owner,
                generation,
                utc_time(typed(row[1], datetime)),
                network,
                typed(row[3], int),
                snap.cursor,
                paused,
            )

    @staticmethod
    def _load_snapshot(
        db: psycopg.Connection[tuple[object, ...]],
        company_id: UUID,
        source_id: UUID,
        snapshot_id: UUID,
    ) -> Snapshot:
        row = db.execute(
            f"SELECT {COLUMNS} FROM month_snapshot WHERE company_id=%s AND source_id=%s AND snapshot_id=%s LIMIT 1",
            (company_id, source_id, snapshot_id),
        ).fetchone()
        if row is None:
            raise CollectionFailure("not_found")
        return snapshot(row)

    def state(self, company_id: UUID, lease: JobLease) -> Snapshot:
        if company_id != lease.company_id:
            raise CollectionFailure("conflict")
        with self._db() as db:
            return self._load_snapshot(
                db, lease.company_id, lease.source_id, lease.snapshot_id
            )

    def _restart(
        self,
        db: psycopg.Connection[tuple[object, ...]],
        company_id: UUID,
        source_id: UUID,
        job_id: UUID,
        old: Snapshot,
        generation: int,
        now: datetime,
    ) -> UUID:
        new_id = uuid7()
        db.execute(
            "UPDATE month_snapshot SET status='error',lease_id=NULL,lease_until=NULL,error_code=COALESCE(error_code,'obsolete_generation') WHERE company_id=%s AND source_id=%s AND snapshot_id=%s AND status<>'ready'",
            (company_id, source_id, old.snapshot_id),
        )
        db.execute(
            "DELETE FROM snapshot_sku_check WHERE company_id=%s AND source_id=%s AND snapshot_id=%s",
            (company_id, source_id, old.snapshot_id),
        )
        self._insert_snapshots(
            db,
            company_id,
            source_id,
            old.parameters.category_path,
            [
                {
                    "snapshot_id": str(new_id),
                    "job_id": str(job_id),
                    "date_from": old.parameters.date_from.isoformat(),
                    "date_to": old.parameters.date_to.isoformat(),
                    "partial": old.parameters.partial,
                }
            ],
            old.limits,
            generation,
            now,
        )
        db.execute(
            "UPDATE research_job SET snapshot_id=%s,attempt_no=attempt_no+1 WHERE company_id=%s AND source_id=%s AND job_id=%s",
            (new_id, company_id, source_id, job_id),
        )
        return new_id

    def apply(
        self,
        company_id: UUID,
        lease: JobLease,
        decision: RetryDecision,
        observed: Snapshot,
        now: datetime,
    ) -> bool:
        if company_id != lease.company_id:
            raise CollectionFailure("conflict")
        with self._db() as db:
            db.execute(
                "SELECT run_id FROM research_run WHERE company_id=%s AND run_id=%s FOR UPDATE",
                (lease.company_id, lease.run_id),
            ).fetchone()
            owned = db.execute(
                "SELECT job_id FROM research_job WHERE company_id=%s AND source_id=%s AND job_id=%s AND snapshot_id=%s AND lease_id=%s AND lease_until>clock_timestamp() AND status='running' FOR UPDATE",
                (
                    lease.company_id,
                    lease.source_id,
                    lease.job_id,
                    lease.snapshot_id,
                    lease.owner,
                ),
            ).fetchone()
            if owned is None:
                return False
            current = self._load_snapshot(
                db, lease.company_id, lease.source_id, lease.snapshot_id
            )
            if current != observed:
                return False
            if decision.restart:
                gate = db.execute(
                    "SELECT active_generation FROM catalog_provider_gate WHERE source_id=%s FOR SHARE",
                    (lease.source_id,),
                ).fetchone()
                if gate is None or gate[0] != lease.generation:
                    return False
                self._restart(
                    db,
                    lease.company_id,
                    lease.source_id,
                    lease.job_id,
                    current,
                    lease.generation,
                    now,
                )
            if decision.status == "error":
                db.execute(
                    "UPDATE month_snapshot SET status='error',lease_id=NULL,lease_until=NULL,error_code=%s WHERE company_id=%s AND source_id=%s AND snapshot_id=%s AND status<>'ready'",
                    (
                        decision.error_code,
                        lease.company_id,
                        lease.source_id,
                        lease.snapshot_id,
                    ),
                )
                db.execute(
                    "DELETE FROM snapshot_sku_check WHERE company_id=%s AND source_id=%s AND snapshot_id=%s",
                    (lease.company_id, lease.source_id, lease.snapshot_id),
                )
            db.execute(
                """UPDATE research_job SET status=%s,next_attempt_at=%s,network_retries=%s,completeness_retries=%s,
                error_code=%s,row_count=%s,lease_id=NULL,lease_until=NULL WHERE company_id=%s AND job_id=%s""",
                (
                    decision.status,
                    decision.next_at,
                    decision.network_retries,
                    decision.completeness_retries,
                    decision.error_code,
                    current.cursor
                    if current.total is not None and not decision.restart
                    else None,
                    lease.company_id,
                    lease.job_id,
                ),
            )
            self._update_run(db, lease.company_id, lease.run_id)
        return True

    @staticmethod
    def _update_run(
        db: psycopg.Connection[tuple[object, ...]], company_id: UUID, run_id: UUID
    ) -> None:
        db.execute(
            """UPDATE research_run r SET revision=revision+1,status=CASE
            WHEN EXISTS(SELECT 1 FROM research_job j WHERE j.company_id=r.company_id AND j.run_id=r.run_id AND j.status IN ('queued','running')) THEN 'running'
            WHEN EXISTS(SELECT 1 FROM research_job j WHERE j.company_id=r.company_id AND j.run_id=r.run_id AND j.status='error') THEN 'error' ELSE 'ready' END
            WHERE company_id=%s AND run_id=%s""",
            (company_id, run_id),
        )

    def reject_run_credentials(
        self, company_id: UUID, lease: JobLease, now: datetime
    ) -> bool:
        # Bounded to this run (<=24 jobs); ready commits win over auth failure.
        if company_id != lease.company_id:
            raise CollectionFailure("conflict")
        with self._db() as db:
            db.execute(
                "SELECT run_id FROM research_run WHERE company_id=%s AND run_id=%s FOR UPDATE",
                (lease.company_id, lease.run_id),
            ).fetchone()
            valid = db.execute(
                "SELECT 1 FROM research_job WHERE company_id=%s AND source_id=%s AND job_id=%s AND snapshot_id=%s AND lease_id=%s AND lease_until>clock_timestamp() AND status='running' FOR UPDATE",
                (
                    lease.company_id,
                    lease.source_id,
                    lease.job_id,
                    lease.snapshot_id,
                    lease.owner,
                ),
            ).fetchone()
            if valid is None:
                return False
            rows = db.execute(
                "SELECT job_id,lease_id,lease_until,snapshot_id FROM research_job WHERE company_id=%s AND source_id=%s AND run_id=%s ORDER BY job_id FOR UPDATE LIMIT 24",
                (lease.company_id, lease.source_id, lease.run_id),
            ).fetchall()
            ours = next(
                (
                    row
                    for row in rows
                    if row[0] == lease.job_id and row[1] == lease.owner
                ),
                None,
            )
            if ours is None:
                return False
            gate = db.execute(
                "SELECT active_generation,paused_generation FROM catalog_provider_gate WHERE source_id=%s FOR SHARE",
                (lease.source_id,),
            ).fetchone()
            if gate is None or gate != (lease.generation, lease.generation):
                return False
            db.execute(
                """UPDATE research_job j SET status=CASE WHEN s.status='ready' AND s.completed_at<j.deadline THEN 'ready' ELSE 'error' END,
                error_code=CASE WHEN s.status='ready' AND s.completed_at<j.deadline THEN NULL ELSE 'credentials_rejected' END,
                row_count=CASE WHEN s.status='ready' THEN s.cursor_row ELSE j.row_count END,lease_id=NULL,lease_until=NULL
                FROM month_snapshot s WHERE j.company_id=%s AND j.source_id=%s AND j.run_id=%s AND j.status IN ('queued','running')
                AND s.company_id=j.company_id AND s.source_id=j.source_id AND s.snapshot_id=j.snapshot_id""",
                (lease.company_id, lease.source_id, lease.run_id),
            )
            db.execute(
                """UPDATE month_snapshot s SET status='error',lease_id=NULL,lease_until=NULL,error_code='authorization_failed'
                FROM research_job j WHERE j.company_id=%s AND j.source_id=%s AND j.run_id=%s AND j.status='error'
                AND s.company_id=j.company_id AND s.source_id=j.source_id AND s.snapshot_id=j.snapshot_id AND s.status<>'ready'""",
                (lease.company_id, lease.source_id, lease.run_id),
            )
            db.execute(
                """DELETE FROM snapshot_sku_check c USING research_job j WHERE j.company_id=%s AND j.source_id=%s AND j.run_id=%s AND j.status='error'
                AND c.company_id=j.company_id AND c.source_id=j.source_id AND c.snapshot_id=j.snapshot_id""",
                (lease.company_id, lease.source_id, lease.run_id),
            )
            self._update_run(db, lease.company_id, lease.run_id)
        return True

    def progress(
        self, company_id: UUID, source_id: UUID, run_id: UUID
    ) -> dict[str, object]:
        with self._db() as db:
            rows = db.execute(
                """SELECT r.revision,r.status,j.status,j.row_count,j.error_code,j.snapshot_id,s.category_path,s.date_from,s.date_to,s.partial
                FROM research_run r JOIN research_job j ON j.company_id=r.company_id AND j.run_id=r.run_id
                JOIN month_snapshot s ON s.company_id=j.company_id AND s.source_id=j.source_id AND s.snapshot_id=j.snapshot_id
                WHERE r.company_id=%s AND r.source_id=%s AND r.run_id=%s ORDER BY j.month LIMIT 25""",
                (company_id, source_id, run_id),
            ).fetchall()
        if not rows:
            raise CollectionFailure("not_found")
        if len(rows) > 24:
            raise CollectionFailure("conflict")
        months: list[dict[str, object]] = []
        for row in rows:
            parameters = MonthParameters(
                typed(row[6], str),
                typed(row[7], date),
                typed(row[8], date),
                typed(row[9], bool),
            )
            months.append(
                {
                    "period": month_payload(parameters),
                    "status": row[2],
                    "row_count": row[3],
                    "error_code": row[4],
                    "snapshot_id": str(row[5]) if row[2] == "ready" else None,
                }
            )
        return {
            "schema_version": "1",
            "company_id": str(company_id),
            "run_id": str(run_id),
            "revision": rows[0][0],
            "status": rows[0][1],
            "months": months,
        }

    def metadata(
        self, company_id: UUID, source_id: UUID, snapshot_id: UUID
    ) -> dict[str, object]:
        with self._db() as db:
            value = self._load_snapshot(db, company_id, source_id, snapshot_id)
        if value.status != "ready" or value.total is None:
            raise CollectionFailure("not_found")
        return {
            "schema_version": "1",
            "company_id": str(company_id),
            "snapshot_id": str(snapshot_id),
            "source_connection_id": str(source_id),
            "period": month_payload(value.parameters),
            "category_path": value.parameters.category_path,
            "fbs": True,
            "currency": "RUB",
            "status": "ready",
            "row_count": value.cursor,
            "total": value.total,
            "page_count": value.pages,
            "created_at": value.created_at.isoformat(),
        }
