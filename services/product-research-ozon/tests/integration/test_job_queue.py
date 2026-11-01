import json
import subprocess
import sys
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass, replace
from datetime import UTC, datetime, timedelta
from pathlib import Path
from threading import Barrier
from uuid import UUID, uuid7

import httpx
import psycopg
import pytest
from fastapi.testclient import TestClient
from jsonschema import Draft202012Validator, FormatChecker

from product_research_ozon.api import create_app
from product_research_ozon.bootstrap import configured_settings
from product_research_ozon.collection.service import MonthCollector
from product_research_ozon.domain.catalog import CatalogUnavailable
from product_research_ozon.domain.jobs import JobLease, RetryPolicy, RunSpec
from product_research_ozon.domain.month import (
    CollectionFailure,
    CollectionLimits,
    MonthParameters,
    Snapshot,
)
from product_research_ozon.domain.storage import ManifestRef, PageRef
from product_research_ozon.infrastructure.catalog_store import CatalogStore
from product_research_ozon.infrastructure.config import Settings
from product_research_ozon.infrastructure.job_store import JobStore
from product_research_ozon.infrastructure.mpstats_items import GatedItems, MpstatsItems
from product_research_ozon.infrastructure.snapshot_store import SnapshotStore
from product_research_ozon.infrastructure.storage import RawStorage
from product_research_ozon.jobs.service import RunService, RunWorker

TOKEN = "synthetic-job-provider-token-" + "x" * 32
LIMITS = CollectionLimits(10000, 30, 16 * 1024 * 1024)


class Clock:
    def __init__(self) -> None:
        self.now = datetime.now(UTC)

    def __call__(self) -> datetime:
        return self.now

    def advance(self, seconds: int = 2) -> None:
        self.now += timedelta(seconds=seconds)


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


def raw(start: int, total: int = 1, duplicate: bool = False) -> bytes:
    ids = list(range(start + 1, min(start + 500, total) + 1))
    if duplicate and start:
        ids[0] = start
    return json.dumps(
        {
            "startRow": start,
            "endRow": start + len(ids),
            "total": total,
            "data": [
                {"id": sku, "sales": 0, "revenue": 0, "unknown": True} for sku in ids
            ],
        }
    ).encode()


@dataclass
class Harness:
    settings: Settings
    clock: Clock
    company: UUID
    other: UUID
    source: UUID
    gate: CatalogStore
    store: JobStore
    objects: RawStorage
    client: GatedItems
    calls: list[int]
    worker: RunWorker
    service: RunService

    def spec(self, months: int = 2, company: UUID | None = None) -> RunSpec:
        return RunSpec(
            company or self.company,
            uuid7(),
            uuid7(),
            self.source,
            "Категория",
            tuple(
                MonthParameters.create("Категория", 2026, m, self.clock())
                for m in range(1, months + 1)
            ),
        )

    def submit(self, spec: RunSpec) -> dict[str, object]:
        return self.service.submit(spec.company_id, spec.payload())

    def tick(self, seconds: int = 2) -> bool:
        self.clock.advance(seconds)
        return self.worker.run_once()

    def progress(self, spec: RunSpec) -> dict[str, object]:
        return self.service.progress(spec.company_id, spec.run_id)


def harness(
    settings: Settings, handler: httpx.MockTransport | None = None, total: int = 1
) -> Harness:
    clock, company, other, source = Clock(), uuid7(), uuid7(), uuid7()
    calls: list[int] = []

    def provider(request: httpx.Request) -> httpx.Response:
        start = json.loads(request.content)["startRow"]
        calls.append(start)
        return httpx.Response(200, content=raw(start, total))

    gate, store, objects = (
        CatalogStore(settings.database_url),
        JobStore(settings.database_url),
        RawStorage(settings),
    )
    client = GatedItems(
        gate,
        MpstatsItems(TOKEN, handler or httpx.MockTransport(provider), clock),
        source,
        1,
        60,
        clock,
    )
    client.activate()

    def execute(lease: JobLease) -> Snapshot:
        return MonthCollector(
            SnapshotStore(settings.database_url, lease, clock), objects, client, clock
        ).step(lease.company_id, lease.source_id, lease.snapshot_id)

    worker = RunWorker(store, source, 1, (company, other), execute, clock)
    return Harness(
        settings,
        clock,
        company,
        other,
        source,
        gate,
        store,
        objects,
        client,
        calls,
        worker,
        RunService(store, source, 1, LIMITS, (company, other), clock),
    )


def validate(definition: str, payload: object) -> None:
    schema = json.loads(
        Path("/contracts/product-research-ozon/v1/schema.json").read_bytes()
    )
    schema["oneOf"] = [{"$ref": "#/$defs/" + definition}]
    Draft202012Validator(schema, format_checker=FormatChecker()).validate(payload)


def test_atomic_http_admission_idempotency_and_real_progress_contract(
    settings: Settings,
) -> None:
    h = harness(settings)
    spec = h.spec()
    with TestClient(create_app(settings, lambda: True, runs=h.service)) as client:
        headers = {"Authorization": "Bearer " + settings.inbound_token}
        url = f"/internal/v1/companies/{h.company}/runs"
        assert client.post(url, json=spec.payload()).status_code == 401
        first = client.post(url, json=spec.payload(), headers=headers)
        assert first.status_code == 202 and first.headers[
            "x-research-source-id"
        ] == str(h.source)
        validate("run_accepted", first.json())
        assert (
            client.post(url, json=spec.payload(), headers=headers).json()
            == first.json()
        )
        bad = {**spec.payload(), "category_path": "Другой путь"}
        assert client.post(url, json=bad, headers=headers).status_code == 409
        assert h.tick() and h.tick()
        response = client.get(url + "/" + str(spec.run_id), headers=headers)
        validate("run_progress", response.json())
        assert (
            response.json()["status"] == "ready" and len(response.json()["months"]) == 2
        )
        snapshot_id = response.json()["months"][0]["snapshot_id"]
        metadata = client.get(
            f"/internal/v1/companies/{h.company}/snapshots/{snapshot_id}",
            headers=headers,
        )
        assert metadata.status_code == 200
        validate("snapshot_metadata", metadata.json())
        assert "sha256" not in metadata.text and "conwix/svc" not in metadata.text
        assert (
            client.get(
                f"/internal/v1/companies/{h.other}/runs/{spec.run_id}", headers=headers
            ).status_code
            == 404
        )
        assert (
            client.get(
                f"/internal/v1/companies/{uuid7()}/runs/{spec.run_id}", headers=headers
            ).status_code
            == 503
        )
        assert (
            client.post(url, json=spec.payload(), headers=headers).json()
            == first.json()
        )
    assert h.calls == [0, 0]


def test_concurrent_accepts_produce_one_run_and_job_set(settings: Settings) -> None:
    h = harness(settings)
    spec = h.spec()
    barrier = Barrier(2)

    def submit(index: int) -> dict[str, object]:
        barrier.wait(timeout=5)
        return h.submit(spec)

    with ThreadPoolExecutor(max_workers=2) as pool:
        receipts = list(pool.map(submit, (1, 2)))
    assert receipts[0] == receipts[1]
    with psycopg.connect(settings.database_url) as db:
        jobs = db.execute(
            "SELECT job_id FROM research_job WHERE company_id=%s AND run_id=%s LIMIT 25",
            (h.company, spec.run_id),
        ).fetchall()
    assert len(jobs) == 2


def test_fair_companies_and_months_and_exclusive_claims(settings: Settings) -> None:
    h = harness(settings, total=501)
    one, two = h.spec(), h.spec(company=h.other)
    h.submit(one)
    h.submit(two)
    leases: list[JobLease] = []
    for _ in range(4):
        lease = h.store.claim_next_for_all_companies(
            h.source, 1, (h.company, h.other), h.clock()
        )
        assert lease is not None
        leases.append(lease)
    assert [l.company_id for l in leases] == [h.company, h.other, h.company, h.other]
    assert len({l.job_id for l in leases}) == 4
    assert (
        h.store.claim_next_for_all_companies(
            h.source, 1, (h.company, h.other), h.clock()
        )
        is None
    )
    stale = replace(leases[0], owner=uuid7())
    observed = h.store.state(h.company, stale)
    assert not h.store.apply(
        h.company,
        stale,
        RetryPolicy.decide(stale, observed, None, h.clock()),
        observed,
        h.clock(),
    )


@pytest.mark.parametrize("status", [202, 429])
def test_persisted_wait_does_not_spend_network_retries(
    settings: Settings, status: int
) -> None:
    h = harness(
        settings,
        httpx.MockTransport(
            lambda request: httpx.Response(status, headers={"Retry-After": "120"})
        ),
    )
    spec = h.spec(1)
    h.submit(spec)
    assert h.tick()
    assert not h.tick()
    with psycopg.connect(settings.database_url) as db:
        row = db.execute(
            "SELECT network_retries,next_attempt_at,status FROM research_job WHERE company_id=%s AND run_id=%s LIMIT 1",
            (h.company, spec.run_id),
        ).fetchone()
    assert row is not None and row[0] == 0 and row[2] == "queued"
    assert row[1] == h.clock() - timedelta(seconds=2) + timedelta(seconds=120)


def test_network_four_attempts_then_terminal_error(settings: Settings) -> None:
    calls = 0

    def provider(request: httpx.Request) -> httpx.Response:
        nonlocal calls
        calls += 1
        return httpx.Response(503, content=TOKEN.encode())

    h = harness(settings, httpx.MockTransport(provider))
    spec = h.spec(1)
    h.submit(spec)
    for seconds in (2, 60, 120, 240):
        assert h.tick(seconds)
    progress = h.progress(spec)
    validate("run_progress", progress)
    assert progress["status"] == "error" and calls == 4
    months = progress["months"]
    assert isinstance(months, list)
    assert months[0]["error_code"] == "retry_exhausted"
    assert not h.tick(1000)


def test_completeness_retry_restarts_entire_month_once(settings: Settings) -> None:
    calls: list[int] = []

    def provider(request: httpx.Request) -> httpx.Response:
        start = json.loads(request.content)["startRow"]
        calls.append(start)
        return httpx.Response(200, content=raw(start, 501, duplicate=len(calls) == 2))

    h = harness(settings, httpx.MockTransport(provider))
    spec = h.spec(1)
    h.submit(spec)
    for _ in range(4):
        assert h.tick()
    assert h.progress(spec)["status"] == "ready" and calls == [0, 500, 0, 500]
    with psycopg.connect(settings.database_url) as db:
        row = db.execute(
            "SELECT completeness_retries,attempt_no FROM research_job WHERE company_id=%s AND run_id=%s LIMIT 1",
            (h.company, spec.run_id),
        ).fetchone()
        snapshots = db.execute(
            "SELECT status FROM month_snapshot WHERE company_id=%s AND source_id=%s ORDER BY created_at,snapshot_id LIMIT 3",
            (h.company, h.source),
        ).fetchall()
    assert row == (1, 2) and sorted(r[0] for r in snapshots) == ["error", "ready"]


def test_second_completeness_failure_is_terminal(settings: Settings) -> None:
    h = harness(
        settings,
        httpx.MockTransport(lambda request: httpx.Response(200, content=b"{}")),
    )
    spec = h.spec(1)
    h.submit(spec)
    assert h.tick() and h.tick()
    progress = h.progress(spec)
    months = progress["months"]
    assert isinstance(months, list)
    assert (
        progress["status"] == "error"
        and months[0]["error_code"] == "incomplete_snapshot"
    )
    assert not h.tick()


def test_auth_pause_keeps_ready_month_and_stops_others_without_api(
    settings: Settings,
) -> None:
    calls = 0

    def provider(request: httpx.Request) -> httpx.Response:
        nonlocal calls
        calls += 1
        return (
            httpx.Response(200, content=raw(0))
            if calls == 1
            else httpx.Response(401, content=TOKEN.encode())
        )

    h = harness(settings, httpx.MockTransport(provider))
    spec = h.spec(3)
    h.submit(spec)
    assert h.tick() and h.tick()
    progress = h.progress(spec)
    months = progress["months"]
    assert isinstance(months, list)
    assert progress["status"] == "error" and [m["status"] for m in months] == [
        "ready",
        "error",
        "error",
    ]
    assert calls == 2 and not h.tick()
    another = h.spec(1, company=h.other)
    h.submit(another)
    assert h.tick() and h.progress(another)["status"] == "error" and calls == 2


def test_deadline_never_shortens_retry_after_into_another_api_call(
    settings: Settings,
) -> None:
    calls = 0

    def provider(request: httpx.Request) -> httpx.Response:
        nonlocal calls
        calls += 1
        return httpx.Response(429, headers={"Retry-After": "172800"})

    h = harness(settings, httpx.MockTransport(provider))
    spec = h.spec(1)
    h.submit(spec)
    assert h.tick() and h.progress(spec)["status"] == "error"
    assert not h.tick(90000) and calls == 1


def test_empty_allowlist_and_source_scope_are_closed(settings: Settings) -> None:
    h = harness(settings)
    closed = RunService(h.store, h.source, 1, LIMITS, (), h.clock)
    with pytest.raises(CollectionFailure, match="not_available"):
        closed.submit(h.company, h.spec().payload())
    assert h.store.claim_next_for_all_companies(h.source, 1, (), h.clock()) is None
    wrong = replace(h.spec(), source_id=uuid7())
    with pytest.raises(CollectionFailure, match="conflict"):
        h.submit(wrong)


def expire(h: Harness, lease: JobLease) -> None:
    with psycopg.connect(h.settings.database_url) as db:
        db.execute(
            "UPDATE research_job SET lease_until=clock_timestamp()-interval '1 second' WHERE company_id=%s AND job_id=%s",
            (lease.company_id, lease.job_id),
        )
        db.execute(
            "UPDATE month_snapshot SET lease_until=clock_timestamp()-interval '1 second' WHERE company_id=%s AND snapshot_id=%s AND lease_id IS NOT NULL",
            (lease.company_id, lease.snapshot_id),
        )


def test_real_concurrent_claims_are_exclusive_and_skip_locked_company(
    settings: Settings,
) -> None:
    h = harness(settings)
    h.submit(h.spec())
    h.submit(h.spec(company=h.other))
    barrier = Barrier(2)

    def claim(index: int) -> JobLease | None:
        barrier.wait(timeout=5)
        return h.store.claim_next_for_all_companies(
            h.source, 1, (h.company, h.other), h.clock()
        )

    with ThreadPoolExecutor(max_workers=2) as pool:
        leases = list(pool.map(claim, (0, 1)))
    assert all(lease is not None for lease in leases)
    assert len({lease.company_id for lease in leases if lease is not None}) == 2
    assert len({lease.job_id for lease in leases if lease is not None}) == 2


@pytest.mark.parametrize("boundary", ["page", "manifest"])
def test_parent_job_fence_is_rechecked_after_s3_write(
    settings: Settings, boundary: str
) -> None:
    h = harness(settings)
    spec = h.spec(1)
    h.submit(spec)
    lease = h.store.claim_next_for_all_companies(h.source, 1, (h.company,), h.clock())
    assert lease is not None

    owned: JobLease = lease

    class ExpiringStorage(RawStorage):
        def save_page(self, company_id: UUID, ref: PageRef, body: bytes) -> None:
            super().save_page(company_id, ref, body)
            if boundary == "page":
                expire(h, owned)

        def save_manifest(
            self, company_id: UUID, ref: ManifestRef, body: bytes
        ) -> None:
            super().save_manifest(company_id, ref, body)
            if boundary == "manifest":
                expire(h, owned)

    collector = MonthCollector(
        SnapshotStore(settings.database_url, lease, h.clock),
        ExpiringStorage(settings),
        h.client,
        h.clock,
    )
    with pytest.raises(CollectionFailure, match="lease_lost"):
        collector.step(lease.company_id, lease.source_id, lease.snapshot_id)
    observed = h.store.state(h.company, lease)
    assert observed.status != "ready"
    assert not h.store.apply(
        h.company,
        lease,
        RetryPolicy.decide(lease, observed, None, h.clock()),
        observed,
        h.clock(),
    )
    assert h.tick() and h.progress(spec)["status"] == "ready"
    # After page commit / manifest write the replay uses the committed cursor.
    assert h.calls == ([0, 0] if boundary == "page" else [0])


def test_crash_after_ready_commit_recovers_after_deadline_without_another_request(
    settings: Settings,
) -> None:
    h = harness(settings)
    spec = h.spec(1)
    h.submit(spec)
    lease = h.store.claim_next_for_all_companies(h.source, 1, (h.company,), h.clock())
    assert lease is not None
    collector = MonthCollector(
        SnapshotStore(settings.database_url, lease, h.clock),
        h.objects,
        h.client,
        h.clock,
    )
    assert (
        collector.step(lease.company_id, lease.source_id, lease.snapshot_id).status
        == "ready"
    )
    # Process dies before job ack; only leases are allowed to expire in SQL.
    expire(h, lease)
    assert h.tick(90000) and h.progress(spec)["status"] == "ready" and h.calls == [0]


def test_transient_second_page_keeps_checkpoint_and_resets_budget(
    settings: Settings,
) -> None:
    calls: list[int] = []

    def provider(request: httpx.Request) -> httpx.Response:
        start = json.loads(request.content)["startRow"]
        calls.append(start)
        return (
            httpx.Response(503)
            if len(calls) == 2
            else httpx.Response(200, content=raw(start, 501))
        )

    h = harness(settings, httpx.MockTransport(provider))
    spec = h.spec(1)
    h.submit(spec)
    assert h.tick() and h.tick() and not h.tick()
    assert (
        h.tick(60) and h.progress(spec)["status"] == "ready" and calls == [0, 500, 500]
    )
    with psycopg.connect(settings.database_url) as db:
        row = db.execute(
            "SELECT network_retries,attempt_no FROM research_job WHERE company_id=%s AND run_id=%s LIMIT 1",
            (h.company, spec.run_id),
        ).fetchone()
    assert row == (0, 1)


def test_rotation_fences_old_worker_and_restarts_snapshot(settings: Settings) -> None:
    h = harness(settings, total=501)
    spec = h.spec(1)
    h.submit(spec)
    lease = h.store.claim_next_for_all_companies(h.source, 1, (h.company,), h.clock())
    assert lease is not None
    assert h.gate.activate_generation(h.source, 2, h.clock())
    with pytest.raises(CollectionFailure, match="obsolete_generation"):
        MonthCollector(
            SnapshotStore(settings.database_url, lease, h.clock),
            h.objects,
            h.client,
            h.clock,
        ).step(lease.company_id, lease.source_id, lease.snapshot_id)
    expire(h, lease)
    renewed = h.store.claim_next_for_all_companies(h.source, 2, (h.company,), h.clock())
    assert renewed is not None and renewed.snapshot_id != lease.snapshot_id
    assert renewed.generation == 2 and not h.store.generation_current(h.source, 1)


def test_explicit_company_must_match_lease_before_database_access(
    settings: Settings,
) -> None:
    h = harness(settings)
    spec = h.spec(1)
    h.submit(spec)
    lease = h.store.claim_next_for_all_companies(h.source, 1, (h.company,), h.clock())
    assert lease is not None
    observed = h.store.state(h.company, lease)
    decision = RetryPolicy.decide(lease, observed, None, h.clock())
    for operation in (
        lambda: h.store.state(h.other, lease),
        lambda: h.store.apply(h.other, lease, decision, observed, h.clock()),
        lambda: h.store.reject_run_credentials(h.other, lease, h.clock()),
    ):
        with pytest.raises(CollectionFailure, match="conflict"):
            operation()


def test_crash_after_page_commit_resets_next_page_retry_budget(
    settings: Settings,
) -> None:
    calls: list[int] = []

    def provider(request: httpx.Request) -> httpx.Response:
        start = json.loads(request.content)["startRow"]
        calls.append(start)
        return (
            httpx.Response(503)
            if len(calls) in (1, 2, 3, 5)
            else httpx.Response(200, content=raw(start, 1001))
        )

    h = harness(settings, httpx.MockTransport(provider))
    spec = h.spec(1)
    h.submit(spec)
    for seconds in (2, 60, 120):
        assert h.tick(seconds)
    h.clock.advance(240)
    lease = h.store.claim_next_for_all_companies(h.source, 1, (h.company,), h.clock())
    assert lease is not None and lease.network_retries == 3
    saved = MonthCollector(
        SnapshotStore(settings.database_url, lease, h.clock),
        h.objects,
        h.client,
        h.clock,
    ).step(h.company, h.source, lease.snapshot_id)
    assert saved.cursor == 500
    expire(h, lease)  # Crash before job apply; successful page already committed.
    assert h.tick()
    progress = h.progress(spec)
    months = progress["months"]
    assert isinstance(months, list)
    assert progress["status"] == "running" and months[0]["status"] == "queued"
    assert h.tick(60) and h.tick() and h.progress(spec)["status"] == "ready"
    assert calls == [0, 0, 0, 0, 500, 500, 1000]


def test_paused_gate_finishes_already_waiting_snapshot_without_spin(
    settings: Settings,
) -> None:
    h = harness(settings)
    spec = h.spec(1)
    h.submit(spec)
    owner = uuid7()
    assert h.gate.provider_claim(h.source, owner, 1, 60, h.clock())
    assert h.tick() and h.progress(spec)["status"] == "running" and h.calls == []
    h.gate.provider_release(
        h.source, owner, 1, CatalogUnavailable("authorization_failed")
    )
    assert h.tick() and h.progress(spec)["status"] == "error" and h.calls == []
    assert not h.tick()


@pytest.mark.parametrize("path", [" ", "bad\x00path"])
def test_invalid_category_is_contract_violation_not_internal_error(
    settings: Settings, path: str
) -> None:
    h = harness(settings)
    spec = h.spec(1)
    with TestClient(create_app(settings, lambda: True, runs=h.service)) as client:
        response = client.post(
            f"/internal/v1/companies/{h.company}/runs",
            json={**spec.payload(), "category_path": path},
            headers={"Authorization": "Bearer " + settings.inbound_token},
        )
    assert (
        response.status_code == 422
        and response.json()["error"]["code"] == "contract_violation"
    )
