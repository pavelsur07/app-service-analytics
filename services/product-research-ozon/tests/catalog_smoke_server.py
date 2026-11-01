"""Isolated two-service smoke only; excluded from the runtime image, no live source."""

import gzip
import hashlib
import json
import os
from pathlib import Path
from urllib.parse import urlsplit
from uuid import UUID

import uvicorn

from product_research_ozon.api import create_app
from product_research_ozon.bootstrap import configured_settings
from product_research_ozon.catalogs.service import CategoryCatalog
from product_research_ozon.domain.catalog import Category
from product_research_ozon.infrastructure.catalog_cursor import CatalogCursor
from product_research_ozon.infrastructure.catalog_store import CatalogStore
from product_research_ozon.infrastructure.mpstats_catalog import parse_categories
from product_research_ozon.infrastructure.readiness import Readiness
from product_research_ozon.infrastructure.storage import RawStorage


class FixtureSource:
    def fetch(self) -> tuple[Category, ...]:
        directory = Path("/service/tests/fixtures/mpstats/ozon/categories")
        manifest = json.loads((directory / "manifest.json").read_text())
        archive = (directory / "category-list.json.gz").read_bytes()
        if hashlib.sha256(archive).hexdigest() != manifest["gzip_sha256"]:
            raise ValueError("fixture_checksum_mismatch")
        raw = gzip.decompress(archive)
        if hashlib.sha256(raw).hexdigest() != manifest["raw_sha256"]:
            raise ValueError("fixture_checksum_mismatch")
        return parse_categories(raw)


def main() -> None:
    settings = configured_settings()
    if urlsplit(settings.database_url).path != "/product_research_ozon_test":
        raise ValueError("test_database_required")
    service = CategoryCatalog(
        CatalogStore(settings.database_url),
        FixtureSource(),
        UUID(os.environ["RESEARCH_CATALOG_SOURCE_ID"]),
        "smoke",
        60,
        CatalogCursor(settings.inbound_token),
    )
    app = create_app(
        settings, Readiness(settings, RawStorage(settings)), catalogs=service
    )
    uvicorn.run(
        app,
        host="0.0.0.0",
        port=8080,
        access_log=False,
        proxy_headers=False,
        log_config="/service/logging.json",
    )


if __name__ == "__main__":
    main()
