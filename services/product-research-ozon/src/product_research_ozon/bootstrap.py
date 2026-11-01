import os

from fastapi import FastAPI

from product_research_ozon.api import create_app
from product_research_ozon.infrastructure.config import Settings
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
    )
    return Settings.from_mapping(
        {
            name: os.environ.get(
                "RESEARCH_" + name.upper(), "false" if name == "worker_enabled" else ""
            )
            for name in names
        }
    )


def application() -> FastAPI:
    settings = configured_settings()
    return create_app(settings, Readiness(settings, RawStorage(settings)))
