import hashlib
import os
import subprocess
import sys
from collections.abc import Iterator
from uuid import uuid7

import psycopg
import pytest
from botocore.exceptions import ClientError
from fastapi.testclient import TestClient

from product_research_ozon.api import create_app
from product_research_ozon.bootstrap import configured_settings
from product_research_ozon.domain.storage import PageRef
from product_research_ozon.infrastructure.config import Settings
from product_research_ozon.infrastructure.readiness import Readiness
from product_research_ozon.infrastructure.storage import RawStorage


@pytest.fixture(scope="module")
def settings() -> Iterator[Settings]:
    configuration = configured_settings()
    assert "/product_research_ozon_test" in configuration.database_url
    for action in ("up", "check", "down", "up", "check"):
        subprocess.run(
            [sys.executable, "-m", "product_research_ozon.migrate", action],
            check=True,
            timeout=30,
        )
    subprocess.run([sys.executable, "tests/dev_bucket.py"], check=True, timeout=10)
    yield configuration


def test_database_role_is_restricted(settings: Settings) -> None:
    with psycopg.connect(settings.database_url) as connection:
        assert connection.execute("SELECT current_user").fetchone() == (
            "svc_product_research_ozon",
        )
        assert connection.execute(
            "SELECT rolsuper, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname = current_user"
        ).fetchone() == (False, False, False)
    for database in ("app", "app_test", "ozon_fetcher"):
        with pytest.raises(psycopg.OperationalError):
            psycopg.connect(settings.database_url, dbname=database, connect_timeout=2)


def test_storage_real_scope_and_immutable_write(settings: Settings) -> None:
    store = RawStorage(settings)
    company = uuid7()
    body = b'{"data":[]}'
    page = PageRef(company, uuid7(), uuid7(), 0, hashlib.sha256(body).hexdigest())
    with pytest.raises(ValueError, match="company_scope_mismatch"):
        store.put_page(uuid7(), page, body)
    store.put_page(company, page, body)
    assert store.read_page(company, page) == body
    with pytest.raises(ValueError, match="company_scope_mismatch"):
        store.read_page(uuid7(), page)
    with pytest.raises(ClientError):
        store.put_page(company, page, body)
    assert store.read_page(company, page) == body


def test_readiness_checks_db_revision_and_storage(settings: Settings) -> None:
    store = RawStorage(settings)
    ready = Readiness(settings, store)
    with TestClient(create_app(settings, ready)) as client:
        assert client.get("/ready").status_code == 200
        with psycopg.connect(settings.database_url, autocommit=True) as connection:
            connection.execute(
                "UPDATE alembic_version SET version_num = 'unknown_revision'"
            )
            try:
                assert client.get("/health").status_code == 200
                assert client.get("/ready").status_code == 503
            finally:
                connection.execute(
                    "UPDATE alembic_version SET version_num = '0001_baseline'"
                )
    values = {name: getattr(settings, name) for name in settings.__dataclass_fields__}
    broken_db = Settings.from_mapping(
        {
            **values,
            "database_url": "postgresql://invalid:secret@127.0.0.1:1/product_research_ozon_test",
        }
    )
    assert not Readiness(broken_db, store)()
    broken_s3 = Settings.from_mapping(
        {**values, "s3_bucket": "research-missing-" + str(uuid7())}
    )
    assert not Readiness(broken_s3, RawStorage(broken_s3))()


def test_worker_is_disabled_without_polling(settings: Settings) -> None:
    result = subprocess.run(
        [sys.executable, "-m", "product_research_ozon.worker"],
        check=True,
        capture_output=True,
        text=True,
        timeout=10,
    )
    assert '"disabled"' in result.stderr
    values = {**os.environ, "RESEARCH_WORKER_ENABLED": "true"}
    result = subprocess.run(
        [sys.executable, "-m", "product_research_ozon.worker"],
        check=False,
        env=values,
        capture_output=True,
        text=True,
        timeout=10,
    )
    assert result.returncode != 0
    assert settings.inbound_token not in result.stderr


def test_postgres_alias_is_supported_by_migrator(settings: Settings) -> None:
    result = subprocess.run(
        [sys.executable, "-m", "product_research_ozon.migrate", "up"],
        env={
            **os.environ,
            "RESEARCH_DATABASE_URL": settings.database_url.replace(
                "postgresql://", "postgres://", 1
            ),
        },
        capture_output=True,
        text=True,
        check=False,
        timeout=30,
    )
    assert result.returncode == 0, result.stderr
