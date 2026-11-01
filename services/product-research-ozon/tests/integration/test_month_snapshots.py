import hashlib
import json
import logging
import subprocess
import sys
import tarfile
from collections.abc import Callable, Iterator
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass
from datetime import UTC, datetime, timedelta
from decimal import Decimal
from pathlib import Path
from threading import Barrier
from uuid import UUID, uuid7

import httpx
import psycopg
import pytest

from product_research_ozon.bootstrap import configured_settings
from product_research_ozon.collection.service import MonthCollector
from product_research_ozon.domain.month import (
    CollectionFailure,
    CollectionLimits,
    MonthParameters,
    Snapshot,
    SnapshotStatus,
)
from product_research_ozon.domain.storage import ManifestRef, PageRef
from product_research_ozon.infrastructure.catalog_store import CatalogStore
from product_research_ozon.infrastructure.config import Settings
from product_research_ozon.infrastructure.mpstats_items import GatedItems, MpstatsItems
from product_research_ozon.infrastructure.snapshot_store import SnapshotStore
from product_research_ozon.infrastructure.storage import RawStorage

TOKEN = "synthetic-month-token-" + "x" * 32
FIXTURE = Path("tests/fixtures/mpstats/ozon/category-items/2026-01")


class Clock:
    def __init__(self) -> None:
        self.now = datetime.now(UTC)

    def __call__(self) -> datetime:
        return self.now

    def advance(self) -> None:
        self.now += timedelta(seconds=2)


@pytest.fixture(scope="module")
def settings() -> Iterator[Settings]:
    value = configured_settings()
    assert value.database_url.endswith("/product_research_ozon_test")
    subprocess.run(
        [sys.executable, "-m", "product_research_ozon.migrate", "up"],
        check=True,
        timeout=30,
    )
    subprocess.run([sys.executable, "tests/dev_bucket.py"], check=True, timeout=10)
    yield value


def raw_page(start: int, total: int, ids: list[int] | None = None) -> bytes:
    keys = (
        ids if ids is not None else list(range(start + 1, min(start + 500, total) + 1))
    )
    rows = [
        {"id": sku, "sales": 0, "balance": 0, "revenue": 0, "future": {"unknown": True}}
        for sku in keys
    ]
    return json.dumps(
        {"startRow": start, "endRow": start + len(rows), "total": total, "data": rows},
        ensure_ascii=False,
    ).encode()


@dataclass
class Harness:
    collector: MonthCollector
    store: SnapshotStore
    raw: RawStorage
    gate: CatalogStore
    source_client: GatedItems
    clock: Clock
    company: UUID
    source: UUID
    calls: list[int]

    def start(self, limits: CollectionLimits | None = None) -> Snapshot:
        return self.collector.start(
            self.company,
            self.source,
            MonthParameters.create("Категория/Товар", 2026, 1, self.clock()),
            limits or CollectionLimits(100000, 200, 200 * 1024 * 1024),
        )

    def step(self, value: Snapshot) -> Snapshot:
        self.clock.advance()
        return self.collector.step(self.company, self.source, value.snapshot_id)


def harness(
    settings: Settings,
    handler: Callable[[httpx.Request], httpx.Response] | None = None,
    total: int = 501,
    source: UUID | None = None,
    generation: int = 1,
) -> Harness:
    clock, calls = Clock(), []

    def request(request: httpx.Request) -> httpx.Response:
        start = json.loads(request.content)["startRow"]
        assert type(start) is int
        calls.append(start)
        return (
            handler(request)
            if handler
            else httpx.Response(200, content=raw_page(start, total))
        )

    gate, raw, store = (
        CatalogStore(settings.database_url),
        RawStorage(settings),
        SnapshotStore(settings.database_url),
    )
    source_id = source or uuid7()
    client = GatedItems(
        gate,
        MpstatsItems(TOKEN, httpx.MockTransport(request), clock),
        source_id,
        generation,
        60,
        clock,
    )
    client.activate()
    return Harness(
        MonthCollector(store, raw, client, clock),
        store,
        raw,
        gate,
        client,
        clock,
        uuid7(),
        source_id,
        calls,
    )


def test_full_last_page_and_ready_repeat_with_scoped_raw_reads(
    settings: Settings,
) -> None:
    h = harness(settings)
    value = h.start()
    first = h.step(value)
    assert (
        first.cursor == 500
        and first.pages == 1
        and first.status == SnapshotStatus.COLLECTING
    )
    with pytest.raises(CollectionFailure, match="not_found"):
        h.collector.read_page(h.company, h.source, value.snapshot_id, 0)
    done = h.step(first)
    assert (done.status, done.cursor, done.total, done.pages) == (
        SnapshotStatus.READY,
        501,
        501,
        2,
    )
    manifest = json.loads(
        h.collector.read_manifest(h.company, h.source, done.snapshot_id)
    )
    assert (
        manifest["rows"] == 501 and manifest["days"] == 31 and manifest["fbs"] is True
    )
    assert manifest["currency"] == "RUB" and manifest["warning"] is None
    for index, start in enumerate((0, 500)):
        assert h.collector.read_page(
            h.company, h.source, done.snapshot_id, index
        ) == raw_page(start, 501)
    assert h.step(done) == done and h.calls == [0, 500]
    for company, source in ((uuid7(), h.source), (h.company, uuid7())):
        with pytest.raises(CollectionFailure, match="not_found"):
            h.collector.read_manifest(company, source, done.snapshot_id)
        with pytest.raises(CollectionFailure, match="not_found"):
            h.collector.read_page(company, source, done.snapshot_id, 0)
    # Seen SKU values are temporary completeness state, not a permanent product fact table.
    with psycopg.connect(settings.database_url) as db:
        assert (
            db.execute(
                "SELECT sku FROM snapshot_sku_check WHERE company_id=%s AND source_id=%s AND snapshot_id=%s LIMIT 1",
                (h.company, h.source, done.snapshot_id),
            ).fetchone()
            is None
        )


@pytest.mark.parametrize("total", [0, 500, 1000])
def test_empty_and_exact_page_boundary_never_fetch_extra_page(
    settings: Settings, total: int
) -> None:
    h = harness(settings, total=total)
    value = h.start()
    for _ in range(max(1, (total + 499) // 500)):
        value = h.step(value)
    assert value.status == SnapshotStatus.READY and value.cursor == total
    assert h.calls == list(range(0, max(1, total), 500))
    body = json.loads(h.collector.read_manifest(h.company, h.source, value.snapshot_id))
    assert body["warning"] == ("empty_category_or_renamed" if total == 0 else None)


@pytest.mark.parametrize("case", ["duplicate", "changed_total", "empty"])
def test_incomplete_month_does_not_replace_previous_pages(
    settings: Settings, case: str
) -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        start = json.loads(request.content)["startRow"]
        return httpx.Response(
            200,
            content=raw_page(0, 501)
            if start == 0
            else raw_page(500, 501, [500])
            if case == "duplicate"
            else raw_page(500, 502)
            if case == "changed_total"
            else raw_page(500, 501, []),
        )

    h = harness(settings, handler)
    first = h.step(h.start())
    with pytest.raises(CollectionFailure):
        h.step(first)
    failed = h.store.get(h.company, h.source, first.snapshot_id)
    assert (
        failed.status == SnapshotStatus.ERROR
        and failed.cursor == 500
        and failed.pages == 1
    )
    assert h.raw.read_object(
        h.company,
        PageRef(
            h.company,
            h.source,
            first.snapshot_id,
            0,
            h.store.page(h.company, h.source, first.snapshot_id, 0).sha256,
        ),
    ) == raw_page(0, 501)
    with pytest.raises(CollectionFailure, match="not_found"):
        h.collector.read_manifest(h.company, h.source, first.snapshot_id)


@pytest.mark.parametrize(
    "limits",
    [
        CollectionLimits(500, 2, 1000000),
        CollectionLimits(1000, 1, 1000000),
        CollectionLimits(1000, 2, 1),
    ],
)
def test_explicit_budgets_prevent_ready(
    settings: Settings, limits: CollectionLimits
) -> None:
    h = harness(settings)
    value = h.start(limits)
    with pytest.raises(CollectionFailure, match="budget_exceeded"):
        value = h.step(value)
        h.step(value)
    assert (
        h.store.get(h.company, h.source, value.snapshot_id).status
        == SnapshotStatus.ERROR
    )


@pytest.mark.parametrize("where", ["page", "manifest", "append", "publish"])
def test_failure_before_publication_never_produces_ready(
    settings: Settings, where: str, monkeypatch: pytest.MonkeyPatch
) -> None:
    h = harness(settings, total=1)
    value = h.start()

    def broken(*args: object, **kwargs: object) -> None:
        raise CollectionFailure(
            "storage_unavailable"
            if where in ("page", "manifest")
            else "database_unavailable"
        )

    target, name = (
        (h.raw, "save_page" if where == "page" else "save_manifest")
        if where in ("page", "manifest")
        else (h.store, "append" if where == "append" else "finish")
    )
    monkeypatch.setattr(target, name, broken)
    with pytest.raises(CollectionFailure):
        h.step(value)
    saved = h.store.get(h.company, h.source, value.snapshot_id)
    assert saved.status != SnapshotStatus.READY and saved.manifest_sha256 is None


def test_real_concurrent_claims_and_expired_owner_cannot_append(
    settings: Settings,
) -> None:
    h = harness(settings, total=1)
    value = h.start()
    barrier = Barrier(2)
    owners = [uuid7(), uuid7()]

    def claim(owner: UUID) -> bool:
        barrier.wait(timeout=5)
        return h.store.claim(
            h.company, h.source, value.snapshot_id, owner, 1, h.clock()
        )

    with ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(claim, owners))
    assert sorted(results) == [False, True]
    winner, loser = owners[results.index(True)], owners[results.index(False)]
    from product_research_ozon.domain.month import parse_page

    with pytest.raises(CollectionFailure, match="lease_lost"):
        h.store.append(
            h.company,
            h.source,
            value.snapshot_id,
            loser,
            parse_page(raw_page(0, 1), 0, 500),
        )
    with psycopg.connect(settings.database_url) as db:
        db.execute(
            "UPDATE month_snapshot SET lease_until=clock_timestamp()-interval '1 second' WHERE company_id=%s AND source_id=%s AND snapshot_id=%s",
            (h.company, h.source, value.snapshot_id),
        )
    assert h.store.claim(h.company, h.source, value.snapshot_id, loser, 1, h.clock())
    with pytest.raises(CollectionFailure, match="lease_lost"):
        h.store.append(
            h.company,
            h.source,
            value.snapshot_id,
            winner,
            parse_page(raw_page(0, 1), 0, 500),
        )


def test_lost_commit_ack_and_crash_after_page_commit_resume_without_extra_request(
    settings: Settings, monkeypatch: pytest.MonkeyPatch
) -> None:
    h = harness(settings)
    value = h.start()
    # Crash after page commit leaves the cursor, lease and objects; emulate lease expiry.
    original_release = h.store.release

    def crash(
        company_id: UUID,
        source_id: UUID,
        snapshot_id: UUID,
        owner: UUID,
        failure: CollectionFailure | None = None,
    ) -> None:
        raise RuntimeError("synthetic_process_crash")

    monkeypatch.setattr(h.store, "release", crash)
    with pytest.raises(RuntimeError, match="synthetic_process_crash"):
        h.step(value)
    monkeypatch.setattr(h.store, "release", original_release)
    with psycopg.connect(settings.database_url) as db:
        db.execute(
            "UPDATE month_snapshot SET lease_until=clock_timestamp()-interval '1 second' WHERE company_id=%s AND source_id=%s AND snapshot_id=%s",
            (h.company, h.source, value.snapshot_id),
        )
    original_finish = h.store.finish

    def lost_ack(
        company_id: UUID,
        source_id: UUID,
        snapshot_id: UUID,
        owner: UUID,
        sha256: str,
        size: int,
        now: datetime,
    ) -> Snapshot:
        original_finish(company_id, source_id, snapshot_id, owner, sha256, size, now)
        raise CollectionFailure("database_unavailable")

    monkeypatch.setattr(h.store, "finish", lost_ack)
    with pytest.raises(CollectionFailure, match="database_unavailable"):
        h.step(value)
    done = h.step(value)
    assert done.status == SnapshotStatus.READY and h.calls == [0, 500]
    assert (
        json.loads(h.collector.read_manifest(h.company, h.source, value.snapshot_id))[
            "rows"
        ]
        == 501
    )


@pytest.mark.parametrize(
    "status,code", [(429, "rate_limited"), (202, "report_pending")]
)
def test_rate_limit_persists_exact_cooldown_without_worker_sleep(
    settings: Settings, status: int, code: str
) -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(
            status, headers={"Retry-After": "120"}, content=TOKEN.encode()
        )

    h = harness(settings, handler)
    value = h.start()
    with pytest.raises(CollectionFailure, match=code) as caught:
        h.step(value)
    saved = h.store.get(h.company, h.source, value.snapshot_id)
    assert (
        saved.status == SnapshotStatus.COLLECTING
        and saved.next_attempt_at == caught.value.retry_at
    )
    assert saved.next_attempt_at == h.clock() + timedelta(seconds=120)
    assert h.step(value).cursor == 0 and h.calls == [0]
    h.clock.advance()
    with pytest.raises(CollectionFailure, match="rate_limited") as blocked:
        h.source_client.fetch(uuid7(), h.source, value.parameters, 0, 500)
    assert blocked.value.retry_at == saved.next_attempt_at and h.calls == [0]


def test_auth_pause_and_generation_fence_apply_to_items_and_catalog(
    settings: Settings,
) -> None:
    h = harness(settings, lambda request: httpx.Response(401, content=TOKEN.encode()))
    value = h.start()
    with pytest.raises(CollectionFailure, match="authorization_failed"):
        h.step(value)
    assert h.gate.provider_status(h.source, 1) == ("authorization_failed", None)
    h.clock.advance()
    assert not h.gate.provider_claim(h.source, uuid7(), 1, 60, h.clock())
    rotated = harness(settings, total=1, source=h.source, generation=2)
    with pytest.raises(CollectionFailure, match="obsolete_generation"):
        h.source_client.fetch(h.company, h.source, value.parameters, 0, 500)
    rotated.clock.now = h.clock() + timedelta(seconds=2)
    done = rotated.step(rotated.start())
    assert done.status == SnapshotStatus.READY


def test_rotation_after_source_fetch_prevents_publication(settings: Settings) -> None:
    h: Harness

    def handler(request: httpx.Request) -> httpx.Response:
        assert h.gate.activate_generation(h.source, 2, h.clock())
        return httpx.Response(200, content=raw_page(0, 1))

    h = harness(settings, handler)
    value = h.start()
    with pytest.raises(CollectionFailure, match="obsolete_generation"):
        h.step(value)
    assert (
        h.store.get(h.company, h.source, value.snapshot_id).status
        != SnapshotStatus.READY
    )


def test_raw_objects_are_idempotent_scoped_and_corruption_is_detected(
    settings: Settings,
) -> None:
    h = harness(settings, total=1)
    done = h.step(h.start())
    record = h.store.page(h.company, h.source, done.snapshot_id, 0)
    ref = PageRef(h.company, h.source, done.snapshot_id, 0, record.sha256)
    body = h.collector.read_page(h.company, h.source, done.snapshot_id, 0)
    h.raw.save_page(h.company, ref, body)
    with pytest.raises(ValueError, match="company_scope_mismatch"):
        h.raw.read_object(uuid7(), ref)
    manifest = ManifestRef(
        h.company, h.source, done.snapshot_id, done.manifest_sha256 or ""
    )
    with pytest.raises(ValueError, match="company_scope_mismatch"):
        h.raw.read_object(uuid7(), manifest)
    h.raw._client.delete_object(Bucket=settings.s3_bucket, Key=ref.key(h.company))
    h.raw._client.put_object(
        Bucket=settings.s3_bucket,
        Key=ref.key(h.company),
        Body=b"corrupt",
        ContentType="application/json",
        IfNoneMatch="*",
    )
    with pytest.raises(CollectionFailure, match="corrupt_raw"):
        h.collector.read_page(h.company, h.source, done.snapshot_id, 0)


def test_telemetry_failure_is_safe_and_source_response_is_not_logged(
    settings: Settings,
    caplog: pytest.LogCaptureFixture,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    h = harness(settings, total=1)

    def broken(*args: object, **kwargs: object) -> None:
        raise OSError("synthetic_logging_failure")

    with caplog.at_level(logging.INFO):
        assert h.step(h.start()).status == SnapshotStatus.READY
    assert (
        TOKEN not in caplog.text
        and "future" not in caplog.text
        and "revenue" not in caplog.text
    )
    monkeypatch.setattr(logging.getLogger("product-research-ozon"), "log", broken)
    assert h.step(h.start()).status == SnapshotStatus.READY


def test_entire_captured_month_102_pages_persists_and_reconstructs_exactly(
    settings: Settings,
) -> None:
    metadata = json.loads((FIXTURE / "manifest.json").read_bytes())
    with tarfile.open(FIXTURE / "json-pages.tar.gz", "r:gz") as archive:

        def handler(request: httpx.Request) -> httpx.Response:
            start = json.loads(request.content)["startRow"]
            member = archive.extractfile(f"pages/{start:06d}.json")
            assert member is not None
            return httpx.Response(200, content=member.read())

        h = harness(settings, handler)
        value = h.collector.start(
            h.company,
            h.source,
            MonthParameters.create(metadata["category_path"], 2026, 1, h.clock()),
            CollectionLimits(100000, 200, 200 * 1024 * 1024),
        )
        for _ in range(102):
            value = h.step(value)
        assert (
            value.status == SnapshotStatus.READY
            and value.cursor == 50702
            and value.pages == 102
        )
        assert h.calls == list(range(0, 50702, 500))
        sales, revenue, zero_sales = 0, Decimal(0), 0
        for index, asset in enumerate(metadata["pages"]):
            raw = h.collector.read_page(h.company, h.source, value.snapshot_id, index)
            assert (
                len(raw) == asset["bytes"]
                and hashlib.sha256(raw).hexdigest() == asset["sha256"]
            )
            parsed = json.loads(raw, parse_float=Decimal)
            for row in parsed["data"]:
                assert len(row) == 62
                sales += row["sales"]
                revenue += row["revenue"]
                zero_sales += row["sales"] == 0
        assert sales == 3541442 and revenue == Decimal(5172621135) and zero_sales > 0
        assert value.byte_size == sum(asset["bytes"] for asset in metadata["pages"])
        assert (
            len(
                json.loads(
                    h.collector.read_manifest(h.company, h.source, value.snapshot_id)
                )["pages"]
            )
            == 102
        )
        assert h.step(value) == value and len(h.calls) == 102


@pytest.mark.parametrize("case", ["generation", "lease"])
def test_fencing_is_rechecked_after_manifest_write(
    settings: Settings, monkeypatch: pytest.MonkeyPatch, case: str
) -> None:
    h = harness(settings, total=1)
    value = h.start()
    original = h.raw.save_manifest

    def after_write(company_id: UUID, ref: ManifestRef, body: bytes) -> None:
        original(company_id, ref, body)
        if case == "generation":
            assert h.gate.activate_generation(h.source, 2, h.clock())
        else:
            with psycopg.connect(settings.database_url) as db:
                db.execute(
                    "UPDATE month_snapshot SET lease_until=clock_timestamp()-interval '1 second' WHERE company_id=%s AND source_id=%s AND snapshot_id=%s",
                    (h.company, h.source, value.snapshot_id),
                )

    monkeypatch.setattr(h.raw, "save_manifest", after_write)
    with pytest.raises(
        CollectionFailure,
        match="obsolete_generation" if case == "generation" else "lease_lost",
    ):
        h.step(value)
    saved = h.store.get(h.company, h.source, value.snapshot_id)
    assert saved.status != SnapshotStatus.READY and saved.manifest_sha256 is None


def test_raw_read_detects_corrupt_manifest_and_missing_object(
    settings: Settings,
) -> None:
    h = harness(settings, total=1)
    value = h.step(h.start())
    ref = ManifestRef(
        h.company, h.source, value.snapshot_id, value.manifest_sha256 or ""
    )
    h.raw._client.delete_object(Bucket=settings.s3_bucket, Key=ref.key(h.company))
    with pytest.raises(CollectionFailure, match="storage_unavailable"):
        h.collector.read_manifest(h.company, h.source, value.snapshot_id)
    h.raw._client.put_object(
        Bucket=settings.s3_bucket,
        Key=ref.key(h.company),
        Body=b"{}",
        ContentType="application/json",
        IfNoneMatch="*",
    )
    with pytest.raises(CollectionFailure, match="corrupt_raw"):
        h.collector.read_manifest(h.company, h.source, value.snapshot_id)


def test_cache_migration_down_up_preserves_catalog_and_raw_objects(
    settings: Settings,
) -> None:
    from product_research_ozon.domain.catalog import Category

    h = harness(settings, total=1)
    value = h.step(h.start())
    page = h.store.page(h.company, h.source, value.snapshot_id, 0)
    ref = PageRef(h.company, h.source, value.snapshot_id, 0, page.sha256)
    original = h.raw.read_object(h.company, ref)
    with psycopg.connect(settings.database_url) as db:
        assert db.execute(
            "SELECT fbs,currency,profile_version FROM month_snapshot WHERE company_id=%s AND source_id=%s AND snapshot_id=%s",
            (h.company, h.source, value.snapshot_id),
        ).fetchone() == (True, "RUB", 1)
    owner = uuid7()
    assert h.gate.claim(h.company, h.source, owner, h.clock())
    version = h.gate.publish(
        h.company, h.source, owner, (Category("Сохранённый каталог"),), h.clock(), 1
    )
    subprocess.run(
        [
            sys.executable,
            "-m",
            "alembic",
            "-c",
            "/service/alembic.ini",
            "downgrade",
            "0002_catalog_cache",
        ],
        check=True,
        timeout=30,
    )
    try:
        assert h.gate.metadata(h.company, h.source) == version
        assert h.raw.read_object(h.company, ref) == original
        with psycopg.connect(settings.database_url) as db:
            assert db.execute("SELECT to_regclass('month_snapshot')").fetchone() == (
                None,
            )
    finally:
        subprocess.run(
            [sys.executable, "-m", "product_research_ozon.migrate", "up"],
            check=True,
            timeout=30,
        )
        subprocess.run(
            [sys.executable, "-m", "product_research_ozon.migrate", "check"],
            check=True,
            timeout=30,
        )
    assert h.gate.metadata(h.company, h.source) == version
    with pytest.raises(CollectionFailure, match="not_found"):
        h.store.get(h.company, h.source, value.snapshot_id)


@pytest.mark.parametrize("status,level", [(429, logging.WARNING), (401, logging.ERROR)])
def test_wait_and_failure_events_have_required_severity(
    settings: Settings, caplog: pytest.LogCaptureFixture, status: int, level: int
) -> None:
    from product_research_ozon.infrastructure.observability import Event

    h = harness(
        settings,
        lambda request: httpx.Response(
            status, headers={"Retry-After": "60"}, content=TOKEN.encode()
        ),
    )
    with (
        caplog.at_level(logging.DEBUG, logger="product-research-ozon"),
        pytest.raises(CollectionFailure),
    ):
        h.step(h.start())
    events = [
        record
        for record in caplog.records
        if isinstance(getattr(record, "research_event", None), Event)
    ]
    assert len(events) == 1 and events[0].levelno == level
    assert TOKEN not in caplog.text


def test_manifest_round_trip_is_independent_of_sql_session_timezone(
    settings: Settings,
) -> None:
    from contextlib import contextmanager

    class MoscowStore(SnapshotStore):
        @contextmanager
        def _db(self) -> Iterator[psycopg.Connection[tuple[object, ...]]]:
            with super()._db() as db:
                db.execute("SET TIME ZONE 'Europe/Moscow'")
                yield db

    h = harness(settings, total=1)
    h.store = MoscowStore(settings.database_url)
    h.collector = MonthCollector(h.store, h.raw, h.source_client, h.clock)
    done = h.step(h.start())
    assert done.status == SnapshotStatus.READY
    manifest = json.loads(
        h.collector.read_manifest(h.company, h.source, done.snapshot_id)
    )
    assert manifest["created_at"].endswith("+00:00") and manifest[
        "completed_at"
    ].endswith("+00:00")
    assert done.created_at.utcoffset() == timedelta(0)
    assert done.completed_at is not None and done.completed_at.utcoffset() == timedelta(
        0
    )
