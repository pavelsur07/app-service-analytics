"""Ephemeral cross-language HTTP peer with synthetic provider bytes; dev image only."""

import json
import subprocess
import sys
import threading
import time
from dataclasses import replace
from datetime import UTC, datetime, timedelta
from pathlib import Path
from uuid import UUID, uuid7

import httpx
import uvicorn

from product_research_ozon.api import create_app
from product_research_ozon.bootstrap import configured_settings
from product_research_ozon.collection.service import MonthCollector
from product_research_ozon.domain.jobs import JobLease
from product_research_ozon.domain.month import CollectionLimits, Snapshot
from product_research_ozon.infrastructure.catalog_store import CatalogStore
from product_research_ozon.infrastructure.job_store import JobStore
from product_research_ozon.infrastructure.mpstats_items import GatedItems, MpstatsItems
from product_research_ozon.infrastructure.snapshot_store import SnapshotStore
from product_research_ozon.infrastructure.storage import RawStorage
from product_research_ozon.jobs.service import RunService, RunWorker


class Clock:
    def __init__(self) -> None:
        self.now = datetime.now(UTC)

    def __call__(self) -> datetime:
        return self.now


if __name__ == "__main__":
    settings = replace(configured_settings(), inbound_token="stage5-http-" + "x" * 40)
    if not settings.database_url.endswith("/product_research_ozon_test"):
        raise SystemExit("test_only")
    subprocess.run(
        [sys.executable, "-m", "product_research_ozon.migrate", "up"],
        check=True,
        timeout=30,
    )
    company, source = UUID(sys.argv[1]), uuid7()
    clock = Clock()
    store, objects = JobStore(settings.database_url), RawStorage(settings)

    def provider(request: httpx.Request) -> httpx.Response:
        return httpx.Response(
            200,
            content=json.dumps(
                {
                    "startRow": 0,
                    "endRow": 1,
                    "total": 1,
                    "data": [{"id": 1, "sales": 0, "revenue": 0}],
                }
            ).encode(),
        )

    client = GatedItems(
        CatalogStore(settings.database_url),
        MpstatsItems("synthetic-" + "x" * 40, httpx.MockTransport(provider), clock),
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

    worker = RunWorker(store, source, 1, (company,), execute, clock)
    service = RunService(
        store, source, 1, CollectionLimits(10000, 30, 16000000), (company,), clock
    )
    Path("/receipt/source").write_text(str(source))

    def work() -> None:
        while True:
            clock.now += timedelta(seconds=2)
            worker.run_once()
            time.sleep(0.05)

    threading.Thread(target=work, daemon=True).start()
    uvicorn.run(
        create_app(settings, lambda: True, runs=service),
        host="0.0.0.0",
        port=8080,
        access_log=False,
        log_level="error",
    )
