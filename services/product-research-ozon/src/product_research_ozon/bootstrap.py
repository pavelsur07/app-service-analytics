import os

from fastapi import FastAPI

from product_research_ozon.api import create_app
from product_research_ozon.catalogs.service import CategoryCatalog
from product_research_ozon.collection.service import MonthCollector
from product_research_ozon.domain.jobs import JobLease
from product_research_ozon.domain.month import Snapshot
from product_research_ozon.infrastructure.catalog_cursor import CatalogCursor
from product_research_ozon.infrastructure.catalog_store import CatalogStore
from product_research_ozon.infrastructure.config import Settings
from product_research_ozon.infrastructure.job_store import JobStore
from product_research_ozon.infrastructure.mpstats_catalog import MpstatsCatalog
from product_research_ozon.infrastructure.mpstats_items import GatedItems, MpstatsItems
from product_research_ozon.infrastructure.readiness import Readiness
from product_research_ozon.infrastructure.snapshot_store import SnapshotStore
from product_research_ozon.infrastructure.storage import RawStorage
from product_research_ozon.jobs.service import RunService, RunWorker


def configured_settings() -> Settings:
    names = (
        "database_url",
        "inbound_token",
        "outbound_token",
        "s3_endpoint",
        "s3_bucket",
        "s3_access_key",
        "s3_secret_key",
        "worker_enabled",
        "catalog_enabled",
        "catalog_source_id",
        "catalog_generation",
        "catalog_rpm",
        "mpstats_api_token",
        "catalog_cursor_secret",
        "collection_enabled",
        "allowed_company_ids",
        "max_rows",
        "max_pages",
        "max_bytes",
    )
    return Settings.from_mapping(
        {
            name: os.environ.get(
                "MPSTATS_API_TOKEN"
                if name == "mpstats_api_token"
                else "RESEARCH_" + name.upper(),
                "false"
                if name in ("worker_enabled", "catalog_enabled", "collection_enabled")
                else "",
            )
            for name in names
        }
    )


def application() -> FastAPI:
    settings = configured_settings()
    catalog = settings.catalog
    service = (
        None
        if catalog is None
        else CategoryCatalog(
            CatalogStore(settings.database_url),
            MpstatsCatalog(catalog.token),
            catalog.source_id,
            catalog.generation,
            catalog.rpm,
            CatalogCursor(catalog.cursor_secret),
        )
    )
    if service is not None:
        service.activate()
    runs = None
    if settings.collection is not None and catalog is not None:
        runs = RunService(
            JobStore(settings.database_url),
            catalog.source_id,
            catalog.generation,
            settings.collection.limits,
            settings.collection.allowed_companies,
        )
    return create_app(
        settings, Readiness(settings, RawStorage(settings)), catalogs=service, runs=runs
    )


def configured_worker(settings: Settings) -> RunWorker:
    collection, catalog = settings.collection, settings.catalog
    if collection is None or catalog is None or not collection.worker_enabled:
        raise ValueError("worker_not_configured")
    source = GatedItems(
        CatalogStore(settings.database_url),
        MpstatsItems(catalog.token),
        catalog.source_id,
        catalog.generation,
        catalog.rpm,
    )
    source.activate()
    storage = RawStorage(settings)

    def execute(lease: JobLease) -> Snapshot:
        return MonthCollector(
            SnapshotStore(settings.database_url, lease), storage, source
        ).step(lease.company_id, lease.source_id, lease.snapshot_id, str(lease.owner))

    return RunWorker(
        JobStore(settings.database_url),
        catalog.source_id,
        catalog.generation,
        collection.allowed_companies,
        execute,
    )
