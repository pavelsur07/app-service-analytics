import os

from fastapi import FastAPI

from product_research_ozon.api import create_app
from product_research_ozon.catalogs.service import CategoryCatalog
from product_research_ozon.infrastructure.catalog_cursor import CatalogCursor
from product_research_ozon.infrastructure.catalog_store import CatalogStore
from product_research_ozon.infrastructure.config import Settings
from product_research_ozon.infrastructure.mpstats_catalog import MpstatsCatalog
from product_research_ozon.infrastructure.readiness import Readiness
from product_research_ozon.infrastructure.storage import RawStorage


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
    )
    return Settings.from_mapping(
        {
            name: os.environ.get(
                "MPSTATS_API_TOKEN"
                if name == "mpstats_api_token"
                else "RESEARCH_" + name.upper(),
                "false" if name in ("worker_enabled", "catalog_enabled") else "",
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
    return create_app(
        settings, Readiness(settings, RawStorage(settings)), catalogs=service
    )
