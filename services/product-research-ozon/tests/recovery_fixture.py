"""Synthetic operator drill peer. Read-only verify never activates a provider."""

import json
import subprocess
import sys
from datetime import UTC, datetime
from pathlib import Path
from uuid import UUID

from product_research_ozon.bootstrap import configured_settings
from product_research_ozon.domain.month import CollectionFailure
from product_research_ozon.infrastructure.diagnostics import JobDiagnostics
from product_research_ozon.infrastructure.job_store import JobStore
from product_research_ozon.infrastructure.snapshot_audit import SnapshotAuditor


def main() -> None:
    settings = configured_settings()
    if not settings.database_url.endswith("/product_research_ozon_test"):
        raise ValueError("sandbox_only")
    receipt = Path("/receipt/snapshot.json")
    if sys.argv[1] == "prepare":
        sys.path.insert(0, "/service/tests/integration")
        from test_job_queue import harness

        subprocess.run(
            [sys.executable, "-m", "product_research_ozon.migrate", "up"],
            check=True,
            timeout=30,
        )
        subprocess.run([sys.executable, "tests/dev_bucket.py"], check=True, timeout=10)
        h = harness(settings, total=501)
        first = h.spec(1)
        h.submit(first)
        assert h.tick() and h.tick()
        second = h.spec(1)
        h.submit(second)
        assert h.tick(10) and h.calls == [0, 500]
        progress = h.service.progress(h.company, second.run_id, True)
        months = progress["months"]
        assert isinstance(months, list) and isinstance(months[0], dict)
        assert months[0]["origin"] == "cache"
        receipt.write_text(
            json.dumps(
                {
                    "company": str(h.company),
                    "source": str(h.source),
                    "foreign": str(h.other),
                    "snapshot": months[0]["snapshot_id"],
                    "run": str(second.run_id),
                }
            )
        )
        print(
            "PASS: source501rows/2pages,2runs,cache reuse without extra source requests"
        )
    elif sys.argv[1] == "verify":
        data = json.loads(receipt.read_text())
        company, source, snapshot, foreign, run = (
            UUID(data[name])
            for name in ("company", "source", "snapshot", "foreign", "run")
        )
        audit = SnapshotAuditor(settings)
        result = audit.verify(company, source, snapshot)
        assert result.pages == 2 and result.rows == 501
        report = JobDiagnostics(settings.database_url).report(
            company, source, datetime.now(UTC), 200
        )
        assert report.ready == 2 and report.cache_ready == 1
        progress = JobStore(settings.database_url).progress(company, source, run, True)
        assert progress["status"] == "ready"
        try:
            audit.verify(foreign, source, snapshot)
        except CollectionFailure as error:
            assert error.code == "not_found"
        else:
            raise AssertionError("foreign_scope_accepted")
        print(
            "PASS: isolated pg_restore retained runs/cache links; manifest and2S3pages exact; foreign scope denied"
        )
    else:
        raise ValueError("invalid_operation")


if __name__ == "__main__":
    main()
