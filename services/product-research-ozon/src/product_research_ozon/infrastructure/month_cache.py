"""Called under the current run/job lock; never locks another owner's job."""

from datetime import datetime
from uuid import UUID

import psycopg
from psycopg.types.json import Jsonb

from product_research_ozon.domain.month import CollectionFailure, Snapshot
from product_research_ozon.domain.month_cache import MonthCacheKey
from product_research_ozon.infrastructure.snapshot_store import COLUMNS, snapshot, typed


def resolve(
    company_id: UUID,
    db: psycopg.Connection[tuple[object, ...]],
    source_id: UUID,
    job_id: UUID,
    current: Snapshot,
    deadline: datetime,
) -> tuple[str, Snapshot]:
    if current.company_id != company_id or current.source_id != source_id:
        raise CollectionFailure("conflict")
    if current.parameters.partial:
        return "api", current
    key = MonthCacheKey(
        company_id,
        source_id,
        current.parameters.category_path,
        current.parameters.date_from,
        current.parameters.date_to,
    )
    inserted = db.execute(
        "INSERT INTO month_cache_slot(company_id,source_id,key_hash,key_payload,owner_job_id) VALUES (%s,%s,%s,%s,%s) ON CONFLICT DO NOTHING RETURNING owner_job_id",
        (company_id, source_id, key.digest, Jsonb(key.payload()), job_id),
    ).fetchone()
    row = db.execute(
        "SELECT owner_job_id,key_payload FROM month_cache_slot WHERE company_id=%s AND source_id=%s AND key_hash=%s FOR UPDATE",
        (company_id, source_id, key.digest),
    ).fetchone()
    if row is None or row[1] != key.payload():
        raise CollectionFailure("conflict")
    owner = typed(row[0], UUID)
    if inserted is not None:
        # Stage-5 ready jobs predate slots. Adoption is serialized by the slot lock.
        # Their DB profile fixes precisely this v1/FBS/RUB/request semantics.
        legacy = db.execute(
            """SELECT j.job_id FROM month_snapshot s JOIN research_job j
            ON j.company_id=s.company_id AND j.source_id=s.source_id AND j.snapshot_id=s.snapshot_id
            WHERE s.company_id=%s AND s.source_id=%s AND s.category_path=%s AND s.date_from=%s AND s.date_to=%s
            AND NOT s.partial AND s.fbs AND s.currency='RUB' AND s.profile_version=1
            AND s.status='ready' AND j.status='ready' AND s.completed_at<%s
            ORDER BY s.completed_at DESC,j.job_id LIMIT 1""",
            (
                company_id,
                source_id,
                key.category_path,
                key.date_from,
                key.date_to,
                deadline,
            ),
        ).fetchone()
        if legacy is not None:
            owner = typed(legacy[0], UUID)
            db.execute(
                "UPDATE month_cache_slot SET owner_job_id=%s WHERE company_id=%s AND source_id=%s AND key_hash=%s",
                (owner, company_id, source_id, key.digest),
            )
    if owner == job_id:
        return "api", current
    columns = ",".join("s." + value for value in COLUMNS.split(","))
    other = db.execute(
        f"""SELECT j.status,{columns} FROM research_job j JOIN month_snapshot s ON s.company_id=j.company_id AND s.source_id=j.source_id AND s.snapshot_id=j.snapshot_id
        WHERE j.company_id=%s AND j.source_id=%s AND j.job_id=%s LIMIT 1""",
        (company_id, source_id, owner),
    ).fetchone()
    if other is None:
        raise CollectionFailure("conflict")
    cached = snapshot(other[1:])
    if (
        other[0] == "ready"
        and cached.status == "ready"
        and cached.completed_at is not None
    ):
        # Consumer deadlines do not invalidate the shared ready result.
        if cached.completed_at >= deadline:
            return "expired", current
        if cached.parameters != current.parameters:
            raise CollectionFailure("conflict")
        if (
            cached.cursor > current.limits.max_rows
            or cached.pages > current.limits.max_pages
            or cached.byte_size > current.limits.max_bytes
        ):
            return "budget", current
        return "cache", cached
    if other[0] in ("queued", "running"):
        return "wait", current
    db.execute(
        "UPDATE month_cache_slot SET owner_job_id=%s WHERE company_id=%s AND source_id=%s AND key_hash=%s",
        (job_id, company_id, source_id, key.digest),
    )
    return "api", current
