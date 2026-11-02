import subprocess
import sys
from dataclasses import replace
from uuid import uuid7

import psycopg
import pytest
from test_job_queue import harness

from product_research_ozon.bootstrap import configured_settings
from product_research_ozon.domain.month import CollectionFailure
from product_research_ozon.infrastructure.config import Settings
from product_research_ozon.infrastructure.diagnostics import JobDiagnostics
from product_research_ozon.infrastructure.snapshot_audit import SnapshotAuditor


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


def test_diagnostics_samples_only_requested_company_and_source(
    settings: Settings,
) -> None:
    h = harness(settings)
    one = h.spec(1)
    h.submit(one)
    assert h.tick()
    two = replace(h.spec(1), project_id=uuid7())
    h.submit(two)
    assert h.tick(10)
    h.submit(h.spec(1, company=h.other))
    diagnostics = JobDiagnostics(settings.database_url)
    report = diagnostics.report(h.company, h.source, h.clock(), 200)
    assert report.sampled == 2 and report.ready == 2 and report.cache_ready == 1
    assert report.api_ready == 1 and report.errors == 0 and not report.truncated
    assert diagnostics.report(h.company, h.source, h.clock(), 1).truncated
    assert diagnostics.report(h.company, uuid7(), h.clock(), 200).sampled == 0
    foreign = diagnostics.report(h.other, h.source, h.clock(), 200)
    assert foreign.sampled == 1 and foreign.queued == 1 and foreign.ready == 0
    with pytest.raises(ValueError):
        diagnostics.report(h.company, h.source, h.clock(), 201)


def test_audit_verifies_linked_objects_and_rejects_foreign_or_corrupt_metadata(
    settings: Settings,
) -> None:
    h = harness(settings, total=501)
    run = h.spec(1)
    h.submit(run)
    assert h.tick() and h.tick()
    progress = h.service.progress(h.company, run.run_id, True)
    with psycopg.connect(settings.database_url) as db:
        row = db.execute(
            "SELECT snapshot_id FROM research_job WHERE company_id=%s AND run_id=%s LIMIT 1",
            (h.company, run.run_id),
        ).fetchone()
    assert row is not None
    snapshot = row[0]
    audit = SnapshotAuditor(settings)
    result = audit.verify(h.company, h.source, snapshot)
    assert result.pages == 2 and result.rows == 501 and result.raw_bytes > 0
    assert progress["status"] == "ready"
    for company, source in ((h.other, h.source), (h.company, uuid7())):
        with pytest.raises(CollectionFailure, match="not_found"):
            audit.verify(company, source, snapshot)
    with psycopg.connect(settings.database_url) as db:
        db.execute(
            "UPDATE snapshot_page SET byte_size=byte_size+1 WHERE company_id=%s AND snapshot_id=%s AND page_no=0",
            (h.company, snapshot),
        )
    with pytest.raises(CollectionFailure, match="corrupt_raw"):
        audit.verify(h.company, h.source, snapshot)
