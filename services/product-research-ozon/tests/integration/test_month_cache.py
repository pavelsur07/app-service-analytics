import json
import subprocess
import sys
from dataclasses import replace
from pathlib import Path
from uuid import uuid7

import pytest
from fastapi.testclient import TestClient
from jsonschema import Draft202012Validator, FormatChecker
from test_job_queue import harness

from product_research_ozon.api import create_app
from product_research_ozon.bootstrap import configured_settings
from product_research_ozon.domain.month import MonthParameters
from product_research_ozon.infrastructure.config import Settings


@pytest.fixture(scope="module")
def settings() -> Settings:
    value = configured_settings()
    assert value.database_url.endswith("/product_research_ozon_test")
    subprocess.run(
        [sys.executable, "-m", "product_research_ozon.migrate", "up"],
        check=True,
        timeout=30,
    )
    subprocess.run([sys.executable, "tests/dev_bucket.py"], check=True, timeout=10)
    return value


def test_closed_cache_reuses_snapshot_without_api_and_v2_dates(
    settings: Settings,
) -> None:
    h = harness(settings)
    one = h.spec(1)
    h.submit(one)
    assert h.tick()
    two = replace(h.spec(1), project_id=uuid7())
    h.submit(two)
    assert h.tick(10)
    assert h.calls == [0]
    first = h.service.progress(h.company, one.run_id, True)
    second = h.service.progress(h.company, two.run_id, True)
    m1 = first["months"]
    m2 = second["months"]
    assert isinstance(m1, list) and isinstance(m2, list)
    assert m1[0]["snapshot_id"] == m2[0]["snapshot_id"] and m2[0]["origin"] == "cache"
    assert (
        m1[0]["collected_at"] == m2[0]["collected_at"]
        and m1[0]["used_at"] < m2[0]["used_at"]
    )
    schema = json.loads(
        Path("/contracts/product-research-ozon/v2/schema.json").read_text()
    )
    Draft202012Validator(schema, format_checker=FormatChecker()).validate(second)
    with TestClient(create_app(settings, lambda: True, runs=h.service)) as client:
        url = f"/internal/v2/companies/{h.company}/runs/{two.run_id}"
        assert client.get(url).json()["schema_version"] == "2"
        response = client.get(
            url, headers={"Authorization": "Bearer " + settings.inbound_token}
        )
        assert response.status_code == 200
        Draft202012Validator(schema, format_checker=FormatChecker()).validate(
            response.json()
        )


def test_collecting_owner_parks_competitor_and_only_one_fetch(
    settings: Settings,
) -> None:
    h = harness(settings, total=501)
    one, two = h.spec(1), h.spec(1)
    h.submit(one)
    h.submit(two)
    assert h.tick()
    assert not h.tick()  # other project waits on the active owner
    assert h.tick()  # owner finishes its second page
    assert h.tick()
    assert h.calls == [0, 500] and h.progress(two)["status"] == "ready"


def test_company_and_partial_do_not_share_cache(settings: Settings) -> None:
    h = harness(settings)
    one = h.spec(1)
    h.submit(one)
    assert h.tick()
    other = h.spec(1, company=h.other)
    h.submit(other)
    assert h.tick()
    assert len(h.calls) == 2
    day = h.clock().replace(day=15)
    h.clock.now = day
    period = MonthParameters.create("Категория", day.year, day.month, day, True)
    first = replace(h.spec(1), months=(period,))
    h.submit(first)
    assert h.tick()
    second = replace(h.spec(1), months=(period,))
    h.submit(second)
    assert h.tick()
    assert len(h.calls) == 4


def test_real_concurrent_claims_and_crashed_owner_recover(settings: Settings) -> None:
    from concurrent.futures import ThreadPoolExecutor
    from threading import Barrier

    from test_job_queue import expire

    h = harness(settings)
    first, second = h.spec(1), h.spec(1)
    h.submit(first)
    h.submit(second)
    barrier = Barrier(2)

    def claim() -> object:
        barrier.wait(timeout=5)
        return h.store.claim_next_for_all_companies(
            h.source, 1, (h.company,), h.clock()
        )

    with ThreadPoolExecutor(max_workers=2) as pool:
        leases = list(pool.map(lambda _: claim(), range(2)))
    winners = [lease for lease in leases if lease is not None]
    assert len(winners) == 1 and h.calls == []
    # This owner never reached the API. Its expired lease must not strand the waiter.
    from product_research_ozon.domain.jobs import JobLease

    assert isinstance(winners[0], JobLease)
    expire(h, winners[0])
    for _ in range(8):
        h.tick(60)
        if h.progress(first)["status"] == h.progress(second)["status"] == "ready":
            break
    assert h.progress(first)["status"] == h.progress(second)["status"] == "ready"
    assert h.calls == [0]


def test_failed_owner_is_replaced_and_does_not_publish_failed_cache(
    settings: Settings,
) -> None:
    import httpx
    from test_job_queue import raw

    calls = []

    def provider(request: httpx.Request) -> httpx.Response:
        calls.append(1)
        return httpx.Response(200, content=b"{}" if len(calls) <= 2 else raw(0))

    h = harness(settings, httpx.MockTransport(provider))
    first, second = h.spec(1), h.spec(1)
    h.submit(first)
    h.submit(second)
    for _ in range(10):
        h.tick(60)
        if h.progress(second)["status"] == "ready":
            break
    assert h.progress(first)["status"] == "error"
    assert h.progress(second)["status"] == "ready" and len(calls) == 3
    third = h.spec(1)
    h.submit(third)
    assert h.tick()
    assert h.progress(third)["status"] == "ready" and len(calls) == 3


def test_completeness_restart_is_followed_by_waiting_project(
    settings: Settings,
) -> None:
    import httpx
    from test_job_queue import raw

    calls = []

    def provider(request: httpx.Request) -> httpx.Response:
        start = json.loads(request.content)["startRow"]
        calls.append(start)
        return httpx.Response(200, content=raw(start, 501, duplicate=len(calls) == 2))

    h = harness(settings, httpx.MockTransport(provider))
    first, second = h.spec(1), h.spec(1)
    h.submit(first)
    h.submit(second)
    for _ in range(12):
        h.tick(60)
        if h.progress(first)["status"] == h.progress(second)["status"] == "ready":
            break
    assert calls == [0, 500, 0, 500]
    a, b = (
        h.progress(first)["months"],
        h.service.progress(h.company, second.run_id, True)["months"],
    )
    assert isinstance(a, list) and isinstance(b, list)
    assert a[0]["snapshot_id"] == b[0]["snapshot_id"] and b[0]["origin"] == "cache"


def test_stricter_budgets_reject_cached_result_without_new_io(
    settings: Settings,
) -> None:
    from product_research_ozon.domain.month import CollectionLimits
    from product_research_ozon.jobs.service import RunService

    h = harness(settings, total=501)
    first = h.spec(1)
    h.submit(first)
    assert h.tick() and h.tick()
    strict = RunService(
        h.store, h.source, 1, CollectionLimits(500, 1, 1024), (h.company,), h.clock
    )
    second = h.spec(1)
    strict.submit(h.company, second.payload())
    assert h.tick()
    result = h.progress(second)["months"]
    assert isinstance(result, list)
    assert (
        result[0]["status"] == "error"
        and result[0]["error_code"] == "contract_violation"
    )
    assert h.calls == [0, 500] and h.progress(first)["status"] == "ready"


def test_waiter_deadline_does_not_steal_active_owner(settings: Settings) -> None:
    import psycopg
    from test_job_queue import expire

    h = harness(settings)
    first, second = h.spec(1), h.spec(1)
    h.submit(first)
    lease = h.store.claim_next_for_all_companies(h.source, 1, (h.company,), h.clock())
    assert lease is not None
    h.submit(second)
    with psycopg.connect(settings.database_url) as db:
        db.execute(
            "UPDATE research_job SET deadline=%s WHERE company_id=%s AND run_id=%s",
            (h.clock(), h.company, second.run_id),
        )
    assert h.tick()
    assert h.progress(second)["status"] == "error" and h.calls == []
    expire(h, lease)
    for _ in range(4):
        h.tick(60)
    assert h.progress(first)["status"] == "ready" and h.calls == [0]


def test_failed_partial_refresh_keeps_previous_ready_snapshot(
    settings: Settings,
) -> None:
    import httpx
    from test_job_queue import raw

    calls = []

    def provider(request: httpx.Request) -> httpx.Response:
        calls.append(1)
        return httpx.Response(200, content=raw(0) if len(calls) == 1 else b"{}")

    h = harness(settings, httpx.MockTransport(provider))
    h.clock.now = h.clock().replace(day=15)
    period = MonthParameters.create(
        "Категория", h.clock().year, h.clock().month, h.clock(), True
    )
    first = replace(h.spec(1), months=(period,))
    h.submit(first)
    assert h.tick()
    original = h.progress(first)
    second = replace(h.spec(1), months=(period,))
    h.submit(second)
    assert h.tick() and h.tick(60)
    assert h.progress(second)["status"] == "error"
    assert h.progress(first) == original and len(calls) == 3


def test_rotation_restarts_owner_then_reuses_across_credential_generations(
    settings: Settings,
) -> None:
    import httpx
    from test_job_queue import LIMITS, TOKEN, raw

    from product_research_ozon.collection.service import MonthCollector
    from product_research_ozon.domain.jobs import JobLease
    from product_research_ozon.domain.month import Snapshot
    from product_research_ozon.infrastructure.mpstats_items import (
        GatedItems,
        MpstatsItems,
    )
    from product_research_ozon.infrastructure.snapshot_store import SnapshotStore
    from product_research_ozon.jobs.service import RunService, RunWorker

    h = harness(settings, total=501)
    first, second = h.spec(1), h.spec(1)
    h.submit(first)
    h.submit(second)
    assert h.tick()
    assert h.gate.activate_generation(h.source, 2, h.clock())
    calls = []

    def provider(request: httpx.Request) -> httpx.Response:
        start = json.loads(request.content)["startRow"]
        calls.append(start)
        return httpx.Response(200, content=raw(start, 501))

    client = GatedItems(
        h.gate,
        MpstatsItems(TOKEN, httpx.MockTransport(provider), h.clock),
        h.source,
        2,
        60,
        h.clock,
    )
    client.activate()

    def execute(lease: JobLease) -> Snapshot:
        return MonthCollector(
            SnapshotStore(settings.database_url, lease, h.clock),
            h.objects,
            client,
            h.clock,
        ).step(lease.company_id, lease.source_id, lease.snapshot_id)

    worker = RunWorker(h.store, h.source, 2, (h.company,), execute, h.clock)
    for _ in range(8):
        h.clock.advance(60)
        worker.run_once()
        if h.progress(first)["status"] == h.progress(second)["status"] == "ready":
            break
    assert h.progress(first)["status"] == h.progress(second)["status"] == "ready"
    assert h.calls == [0] and calls == [0, 500]
    third = h.spec(1)
    service = RunService(h.store, h.source, 2, LIMITS, (h.company,), h.clock)
    service.submit(h.company, third.payload())
    h.clock.advance(60)
    assert worker.run_once() and len(calls) == 2
    metadata = service.progress(h.company, third.run_id, True)["months"]
    assert isinstance(metadata, list) and metadata[0]["origin"] == "cache"


def test_ready_snapshot_from_before_cache_migration_is_reused(
    settings: Settings,
) -> None:
    import psycopg

    h = harness(settings)
    first = h.spec(1)
    h.submit(first)
    assert h.tick()
    # Stage 5 had no slots; its ready journal and raw snapshots remain valid.
    with psycopg.connect(settings.database_url) as db:
        db.execute(
            "DELETE FROM month_cache_slot WHERE company_id=%s AND source_id=%s",
            (h.company, h.source),
        )
    second = h.spec(1)
    h.submit(second)
    assert h.tick()
    assert h.calls == [0]
    result = h.service.progress(h.company, second.run_id, True)["months"]
    assert isinstance(result, list) and result[0]["origin"] == "cache"


def test_expired_consumer_cannot_evict_ready_owner(settings: Settings) -> None:
    from datetime import timedelta

    import httpx
    import psycopg
    from test_job_queue import raw

    calls = []

    def provider(request: httpx.Request) -> httpx.Response:
        calls.append(1)
        h.clock.advance(20)
        return httpx.Response(200, content=raw(0))

    h = harness(settings, httpx.MockTransport(provider))
    early, owner = h.spec(1), h.spec(1)
    h.submit(early)
    with psycopg.connect(settings.database_url) as db:
        db.execute(
            "UPDATE research_job SET next_attempt_at=%s,deadline=%s WHERE company_id=%s AND run_id=%s",
            (
                h.clock() + timedelta(hours=1),
                h.clock() + timedelta(seconds=10),
                h.company,
                early.run_id,
            ),
        )
    h.submit(owner)
    assert h.tick() and h.progress(owner)["status"] == "ready"
    assert h.tick() and h.progress(early)["status"] == "error"
    later = h.spec(1)
    h.submit(later)
    assert h.tick() and h.progress(later)["status"] == "ready"
    assert len(calls) == 1


def test_rotation_cannot_bypass_cached_budget_rejection(settings: Settings) -> None:
    from product_research_ozon.domain.jobs import JobLease
    from product_research_ozon.domain.month import CollectionLimits, Snapshot
    from product_research_ozon.jobs.service import RunService, RunWorker

    h = harness(settings, total=501)
    first = h.spec(1)
    h.submit(first)
    assert h.tick() and h.tick()
    strict = RunService(
        h.store, h.source, 1, CollectionLimits(500, 1, 1024), (h.company,), h.clock
    )
    second = h.spec(1)
    strict.submit(h.company, second.payload())
    assert h.gate.activate_generation(h.source, 2, h.clock())
    attempted = []

    def execute(lease: JobLease) -> Snapshot:
        attempted.append(1)
        return h.store.state(h.company, lease)

    worker = RunWorker(h.store, h.source, 2, (h.company,), execute, h.clock)
    h.clock.advance(60)
    assert worker.run_once()
    assert attempted == []
    assert h.progress(second)["status"] == "error"
