import json
import os
import subprocess
import sys
from concurrent.futures import ThreadPoolExecutor
from datetime import UTC, datetime, timedelta
from pathlib import Path
from uuid import UUID, uuid7

import psycopg
import pytest
from jsonschema import Draft202012Validator, FormatChecker

from product_research_ozon.catalogs.service import CategoryCatalog
from product_research_ozon.domain.catalog import CatalogUnavailable, Category
from product_research_ozon.infrastructure.catalog_cursor import CatalogCursor
from product_research_ozon.infrastructure.catalog_store import CatalogStore


class Clock:
    def __init__(self) -> None:
        self.now = datetime.now(UTC)

    def __call__(self) -> datetime:
        return self.now


class Source:
    def __init__(self) -> None:
        self.calls = 0
        self.values = (
            Category("Catalog/First"),
            Category("Catalog/Second"),
            Category("Catalog/Third"),
        )
        self.failure: CatalogUnavailable | None = None

    def fetch(self) -> tuple[Category, ...]:
        self.calls += 1
        if self.failure:
            raise self.failure
        return self.values


@pytest.fixture(scope="module")
def database() -> str:
    url = os.environ["RESEARCH_DATABASE_URL"]
    assert url.endswith("/product_research_ozon_test")
    subprocess.run(
        [sys.executable, "-m", "product_research_ozon.migrate", "up"],
        check=True,
        timeout=30,
    )
    return url


def catalog(
    database: str,
    clock: Clock,
    source: Source,
    connection: UUID | None = None,
    generation: int = 1,
) -> CategoryCatalog:
    service = CategoryCatalog(
        CatalogStore(database),
        source,
        connection or uuid7(),
        generation,
        60,
        CatalogCursor("test-signing-secret-" + "s" * 32),
        clock,
    )
    service.activate()
    return service


def test_repeat_pages_share_cache_then_expire_and_match_v1(database: str) -> None:
    clock, source, company = Clock(), Source(), uuid7()
    cache = catalog(database, clock, source)
    first = cache.page(company, 1, None)
    assert isinstance(first["next_cursor"], str)
    second = cache.page(company, 1, first["next_cursor"])
    assert first["revision"] == second["revision"] == 1
    assert first["items"] != second["items"]
    assert source.calls == 1
    schema = json.loads(
        Path("/contracts/product-research-ozon/v1/schema.json").read_text()
    )
    schema["oneOf"] = [{"$ref": "#/$defs/categories_response"}]
    Draft202012Validator(schema, format_checker=FormatChecker()).validate(first)
    clock.now += timedelta(hours=24, microseconds=-1)
    assert cache.page(company, 100, None)["generated_at"] == first["generated_at"]
    assert source.calls == 1
    clock.now += timedelta(microseconds=1)
    assert cache.page(company, 100, None)["revision"] == 2
    assert source.calls == 2
    with pytest.raises(CatalogUnavailable, match="conflict"):
        cache.page(company, 1, first["next_cursor"])


def test_company_and_connection_namespaces_and_cursor_are_isolated(
    database: str,
) -> None:
    clock, source, company, connection = Clock(), Source(), uuid7(), uuid7()
    cache = catalog(database, clock, source, connection)
    first = cache.page(company, 1, None)
    other = uuid7()
    clock.now += timedelta(seconds=2)
    assert cache.page(other, 100, None)["company_id"] == str(other)
    assert source.calls == 2
    changed = catalog(database, clock, source, uuid7())
    changed.page(company, 100, None)
    assert source.calls == 3
    assert isinstance(first["next_cursor"], str)
    with pytest.raises(CatalogUnavailable, match="conflict"):
        cache.page(other, 1, first["next_cursor"])


def test_real_concurrent_claim_and_global_provider_gate(database: str) -> None:
    store, company, connection, now = (
        CatalogStore(database),
        uuid7(),
        uuid7(),
        datetime.now(UTC),
    )
    owners = [uuid7(), uuid7()]
    with ThreadPoolExecutor(max_workers=2) as executor:
        claims = list(
            executor.map(
                lambda owner: store.claim(company, connection, owner, now), owners
            )
        )
    assert sorted(claims) == [False, True]
    assert store.activate_generation(connection, 1, now)
    with ThreadPoolExecutor(max_workers=2) as executor:
        global_claims = list(
            executor.map(
                lambda owner: store.provider_claim(connection, owner, 1, 60, now),
                owners,
            )
        )
    assert sorted(global_claims) == [False, True]
    other_source = uuid7()
    assert store.activate_generation(other_source, 1, now)
    assert store.provider_claim(other_source, uuid7(), 1, 60, now)


def test_lost_lease_cannot_publish_and_failure_preserves_full_generation(
    database: str,
) -> None:
    clock, source, company, connection = Clock(), Source(), uuid7(), uuid7()
    cache = catalog(database, clock, source, connection)
    cache.page(company, 100, None)
    store = CatalogStore(database)
    clock.now += timedelta(days=1)
    source.failure = CatalogUnavailable("invalid_source")
    with pytest.raises(CatalogUnavailable):
        cache.page(company, 100, None)
    previous = store.metadata(company, connection)
    assert previous is not None and previous.revision == 1
    with psycopg.connect(database) as db:
        db.execute(
            "UPDATE catalog_state SET next_attempt_at=NULL WHERE company_id=%s AND source_id=%s",
            (company, connection),
        )
    old, new = uuid7(), uuid7()
    assert store.claim(company, connection, old, clock.now)
    with psycopg.connect(database) as db:
        db.execute(
            "UPDATE catalog_state SET lease_until=clock_timestamp()-interval '1 second' WHERE company_id=%s AND source_id=%s",
            (company, connection),
        )
    assert store.claim(company, connection, new, clock.now)
    with pytest.raises(CatalogUnavailable, match="lease_lost"):
        store.publish(company, connection, old, (), clock.now, 1)
    store.publish(company, connection, new, (), clock.now, 1)
    assert cache.page(company, 100, None)["items"] == []


def test_auth_pause_only_resets_for_rotated_generation(database: str) -> None:
    clock, source, connection, company = Clock(), Source(), uuid7(), uuid7()
    source.failure = CatalogUnavailable("authorization_failed")
    with pytest.raises(CatalogUnavailable, match="authorization_failed"):
        catalog(database, clock, source, connection).page(company, 100, None)
    clock.now += timedelta(minutes=2)
    with pytest.raises(CatalogUnavailable, match="provider_paused"):
        catalog(database, clock, source, connection).page(uuid7(), 100, None)
    assert source.calls == 1
    source.failure = None
    assert (
        catalog(database, clock, source, connection, 2).page(uuid7(), 100, None)[
            "revision"
        ]
        == 1
    )
    assert source.calls == 2


def test_real_internal_http_pages_and_foreign_cursor(database: str) -> None:
    from fastapi.testclient import TestClient

    from product_research_ozon.api import create_app
    from product_research_ozon.bootstrap import configured_settings

    configuration = configured_settings()
    clock, source, company = Clock(), Source(), uuid7()
    cache = catalog(database, clock, source)
    headers = {"Authorization": "Bearer " + configuration.inbound_token}
    with TestClient(create_app(configuration, lambda: True, catalogs=cache)) as client:
        url = f"/internal/v1/companies/{company}/categories"
        assert client.get(url).status_code == 401
        assert (
            client.get(
                url, headers={"Authorization": "Bearer " + configuration.outbound_token}
            ).status_code
            == 401
        )
        assert source.calls == 0
        first = client.get(url, params={"limit": 1}, headers=headers)
        assert first.status_code == 200
        token = first.json()["next_cursor"]
        assert isinstance(token, str)
        second = client.get(url, params={"limit": 1, "cursor": token}, headers=headers)
        assert second.status_code == 200
        assert second.json()["revision"] == first.json()["revision"]
        assert source.calls == 1
        assert (
            client.get(
                f"/internal/v1/companies/{uuid7()}/categories",
                params={"cursor": token},
                headers=headers,
            ).status_code
            == 409
        )
        assert (
            client.get(url, params={"cursor": token[:-1]}, headers=headers).status_code
            == 409
        )
        assert client.get(url + "?limit=1&limit=2", headers=headers).status_code == 422
        assert client.get(url + "?unknown=true", headers=headers).status_code == 422
        assert source.calls == 1


def test_retired_process_cannot_clear_new_generation_auth_pause(database: str) -> None:
    clock, source, connection = Clock(), Source(), uuid7()
    source.failure = CatalogUnavailable("authorization_failed")
    retired = catalog(database, clock, source, connection, 1)
    with pytest.raises(CatalogUnavailable, match="authorization_failed"):
        retired.page(uuid7(), 100, None)
    clock.now += timedelta(minutes=2)
    current = catalog(database, clock, source, connection, 2)
    with pytest.raises(CatalogUnavailable, match="authorization_failed"):
        current.page(uuid7(), 100, None)
    clock.now += timedelta(minutes=2)
    with pytest.raises(CatalogUnavailable, match="obsolete_generation"):
        retired.page(uuid7(), 100, None)
    store = CatalogStore(database)
    assert not store.activate_generation(connection, 1, clock.now)
    assert not store.provider_claim(connection, uuid7(), 1, 60, clock.now)
    assert not store.provider_claim(connection, uuid7(), 2, 60, clock.now)
    with pytest.raises(CatalogUnavailable, match="obsolete_generation"):
        catalog(database, clock, source, connection, 1)
    with psycopg.connect(database) as db:
        assert db.execute(
            "SELECT active_generation,paused_generation FROM catalog_provider_gate WHERE source_id=%s",
            (connection,),
        ).fetchone() == (2, 2)
    assert source.calls == 2


def test_rotation_fences_inflight_publication_and_stale_auth_release(
    database: str,
) -> None:
    clock, source, company, connection = Clock(), Source(), uuid7(), uuid7()
    store = CatalogStore(database)
    owner = uuid7()
    assert store.activate_generation(connection, 1, clock.now)
    assert store.claim(company, connection, owner, clock.now)
    assert store.provider_claim(connection, owner, 1, 60, clock.now)
    assert store.activate_generation(connection, 2, clock.now)
    with pytest.raises(CatalogUnavailable, match="obsolete_generation"):
        store.publish(company, connection, owner, source.values, clock.now, 1)
    assert store.metadata(company, connection) is None
    store.provider_release(
        connection, owner, 1, CatalogUnavailable("authorization_failed")
    )
    clock.now += timedelta(seconds=2)
    assert store.provider_claim(connection, uuid7(), 2, 60, clock.now)
