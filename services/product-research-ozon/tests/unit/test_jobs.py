from dataclasses import replace
from datetime import UTC, datetime, timedelta
from uuid import UUID, uuid7

import pytest

from product_research_ozon.domain.jobs import JobLease, RetryPolicy, RunSpec
from product_research_ozon.domain.month import (
    CollectionFailure,
    CollectionLimits,
    MonthParameters,
    Snapshot,
    SnapshotStatus,
)

NOW = datetime(2026, 11, 1, 12, tzinfo=UTC)
A = UUID("019a3c00-0000-7000-8000-000000000001")
B = UUID("019a3c00-0000-7000-8000-000000000002")


def state() -> tuple[JobLease, Snapshot]:
    parameters = MonthParameters.create("Категория", 2026, 1, NOW)
    snapshot = Snapshot(
        A,
        B,
        uuid7(),
        parameters,
        CollectionLimits(1000, 2, 1000000),
        1,
        SnapshotStatus.COLLECTING,
        NOW,
        None,
        None,
        0,
        0,
        0,
        None,
        None,
        None,
        None,
    )
    lease = JobLease(
        A,
        B,
        uuid7(),
        uuid7(),
        snapshot.snapshot_id,
        uuid7(),
        1,
        NOW + timedelta(hours=24),
        0,
        0,
        0,
    )
    return lease, snapshot


def test_network_budget_is_three_retries_and_page_success_resets_it() -> None:
    lease, snapshot = state()
    for count, seconds in enumerate((60, 120, 240)):
        decision = RetryPolicy.decide(
            replace(lease, network_retries=count),
            snapshot,
            CollectionFailure("source_unavailable"),
            NOW,
        )
        assert decision.status == "queued" and decision.next_at == NOW + timedelta(
            seconds=seconds
        )
        assert decision.network_retries == count + 1
    exhausted = RetryPolicy.decide(
        replace(lease, network_retries=3),
        snapshot,
        CollectionFailure("source_unavailable"),
        NOW,
    )
    assert exhausted.status == "error" and exhausted.error_code == "retry_exhausted"
    progressed = RetryPolicy.decide(
        replace(lease, network_retries=3),
        replace(snapshot, total=501, cursor=500, pages=1),
        None,
        NOW,
    )
    assert progressed.network_retries == 0


def test_retry_after_is_not_shortened_and_does_not_spend_network_budget() -> None:
    lease, snapshot = state()
    for code in ("rate_limited", "report_pending"):
        decision = RetryPolicy.decide(
            replace(lease, network_retries=2),
            snapshot,
            CollectionFailure(code, NOW + timedelta(hours=2)),
            NOW,
        )
        assert (
            decision.next_at == NOW + timedelta(hours=2)
            and decision.network_retries == 2
        )
        expired = RetryPolicy.decide(
            lease, snapshot, CollectionFailure(code, NOW + timedelta(days=2)), NOW
        )
        assert expired.status == "error" and expired.error_code == "deadline_exceeded"


def test_completeness_retry_is_once_and_ready_commit_survives_late_recovery() -> None:
    lease, snapshot = state()
    first = RetryPolicy.decide(lease, snapshot, CollectionFailure("incomplete"), NOW)
    assert first.restart and first.completeness_retries == 1
    second = RetryPolicy.decide(
        replace(lease, completeness_retries=1),
        snapshot,
        CollectionFailure("invalid_source"),
        NOW,
    )
    assert second.status == "error" and second.error_code == "incomplete_snapshot"
    done = replace(
        snapshot,
        status=SnapshotStatus.READY,
        total=1,
        cursor=1,
        completed_at=NOW + timedelta(minutes=1),
    )
    recovered = RetryPolicy.decide(
        lease, done, CollectionFailure("database_unavailable"), NOW + timedelta(days=2)
    )
    assert recovered.status == "ready"


def payload() -> dict[str, object]:
    return {
        "schema_version": "1",
        "company_id": str(A),
        "run_id": str(uuid7()),
        "project_id": str(uuid7()),
        "source_connection_id": str(B),
        "category_path": "Категория",
        "fbs": True,
        "currency": "RUB",
        "months": [
            {
                "month": "2026-10",
                "date_from": "2026-10-01",
                "date_to": "2026-10-30",
                "days": 30,
                "partial": True,
            }
        ],
    }


def test_delayed_frozen_partial_month_does_not_expand_after_midnight() -> None:
    raw = payload()
    run = RunSpec.from_payload(raw, NOW)
    assert run.months[0].date_to.isoformat() == "2026-10-30" and run.months[0].partial
    assert run.payload() == raw
    changed = {**raw, "currency": "USD"}
    with pytest.raises(ValueError):
        RunSpec.from_payload(changed, NOW)
    months = raw["months"]
    assert isinstance(months, list)
    with pytest.raises(ValueError):
        RunSpec.from_payload({**raw, "months": [months[0], months[0]]}, NOW)


def test_collection_configuration_requires_explicit_budgets_and_allowlist_is_closed() -> (
    None
):
    from product_research_ozon.infrastructure.config import (
        CatalogSettings,
        CollectionSettings,
    )

    catalog = CatalogSettings(B, 1, 60, "synthetic-" + "x" * 40, "cursor-" + "y" * 40)
    assert CollectionSettings.from_mapping({}, catalog) is None
    values = {
        "collection_enabled": "true",
        "max_rows": "1000",
        "max_pages": "10",
        "max_bytes": "1000000",
    }
    settings = CollectionSettings.from_mapping(values, catalog)
    assert (
        settings is not None
        and settings.allowed_companies == ()
        and not settings.worker_enabled
    )
    for bad in (
        {"worker_enabled": "true"},
        {**values, "max_rows": ""},
        {**values, "allowed_company_ids": str(A) + "," + str(A)},
        {**values, "allowed_company_ids": "bad"},
    ):
        with pytest.raises(ValueError):
            CollectionSettings.from_mapping(bad, catalog)
    with pytest.raises(ValueError):
        CollectionSettings.from_mapping(values, None)


def test_worker_enabled_runtime_drains_then_idles_and_stops(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    import signal
    import time
    from collections.abc import Callable
    from types import FrameType

    from product_research_ozon import worker
    from product_research_ozon.infrastructure.config import CollectionSettings, Settings

    values = Settings(
        "postgresql://test:synthetic@postgres/product_research_ozon_test",
        "inbound-" + "a" * 40,
        "outbound-" + "b" * 40,
        "http://s3:8333",
        "conwix-test",
        "test",
        "synthetic",
        collection=CollectionSettings(CollectionLimits(1000, 10, 1000000), (A,), True),
    )
    stops: list[Callable[[int, FrameType | None], None]] = []
    sleeps: list[int] = []
    calls = 0

    class Ready:
        def __init__(self, *args: object) -> None:
            pass

        def __call__(self) -> bool:
            return True

    class Drain:
        def run_once(self) -> bool:
            nonlocal calls
            calls += 1
            return calls == 1

    def register(signum: int, handler: Callable[[int, FrameType | None], None]) -> None:
        stops.append(handler)

    def idle(seconds: int) -> None:
        sleeps.append(seconds)
        stops[0](signal.SIGTERM, None)

    monkeypatch.setattr(worker, "configured_settings", lambda: values)
    monkeypatch.setattr(worker, "configured_worker", lambda settings: Drain())
    monkeypatch.setattr(worker, "Readiness", Ready)
    monkeypatch.setattr(signal, "signal", register)
    monkeypatch.setattr(time, "sleep", idle)
    worker.main()
    assert calls == 2 and sleeps == [1] and len(stops) == 2
