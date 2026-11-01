import gzip
import hashlib
import json
from datetime import UTC, datetime, timedelta
from pathlib import Path
from uuid import uuid7

import pytest

from product_research_ozon.domain.catalog import (
    CatalogUnavailable,
    CatalogVersion,
    Category,
)
from product_research_ozon.infrastructure.catalog_cursor import CatalogCursor
from product_research_ozon.infrastructure.mpstats_catalog import parse_categories

FIXTURE = Path("tests/fixtures/mpstats/ozon/categories")
NOW = datetime(2026, 11, 1, 10, tzinfo=UTC)


def test_real_catalog_checksum_paths_and_absence_of_source_id() -> None:
    archive = (FIXTURE / "category-list.json.gz").read_bytes()
    raw = gzip.decompress(archive)
    manifest = json.loads((FIXTURE / "manifest.json").read_text())
    assert hashlib.sha256(archive).hexdigest() == manifest["gzip_sha256"]
    assert hashlib.sha256(raw).hexdigest() == manifest["raw_sha256"]
    categories = parse_categories(raw)
    assert len(categories) == 6567
    found = [category for category in categories if "хидж" in category.path.casefold()]
    assert [category.path for category in found] == [
        "Аксессуары/Женские аксессуары/Платки и шарфы женские/Хиджабы и тюрбаны женские"
    ]
    assert all(category.source_id is None for category in categories)
    assert len({category.key for category in categories}) == 6567


@pytest.mark.parametrize(
    "body",
    [
        b"null",
        b"{}",
        b"[{}]",
        b'[{"name":false}]',
        b'[{"name":""}]',
        b'[{"name":"bad\\u0000path"}]',
        b'[{"name":"same"},{"name":"same"}]',
        b"not json",
    ],
)
def test_invalid_catalog_is_not_publishable(body: bytes) -> None:
    with pytest.raises(CatalogUnavailable):
        parse_categories(body)


def test_empty_catalog_and_optional_provider_id() -> None:
    assert parse_categories(b"[]") == ()
    assert parse_categories(b'[{"name":"Catalog/Leaf","id":123}]') == (
        Category("Catalog/Leaf", "123"),
    )


def test_ttl_is_24_hours_from_source_generation() -> None:
    snapshot = CatalogVersion(1, NOW)
    assert snapshot.is_fresh(NOW + timedelta(hours=24, microseconds=-1))
    assert not snapshot.is_fresh(NOW + timedelta(hours=24))
    assert not snapshot.is_fresh(NOW - timedelta(seconds=1))
    assert snapshot.generated_at == NOW


def test_cursor_binds_company_connection_revision_and_deadline() -> None:
    company, source = uuid7(), uuid7()
    cursor = CatalogCursor("cursor-test-secret-" + "a" * 32)
    token = cursor.encode(company, source, 7, "a" * 64, NOW + timedelta(days=1))
    assert len(token) <= 256
    assert cursor.decode(token, company, source, 7, NOW) == "a" * 64
    for target_company, target_source, revision, at in [
        (uuid7(), source, 7, NOW),
        (company, uuid7(), 7, NOW),
        (company, source, 8, NOW),
        (company, source, 7, NOW + timedelta(days=1)),
    ]:
        with pytest.raises(CatalogUnavailable, match="conflict"):
            cursor.decode(token, target_company, target_source, revision, at)
    replacement = "AA" if token[-2:] != "AA" else "BA"
    for invalid in ["", "%%%bad", token[:-1], token[:-2] + replacement]:
        with pytest.raises(CatalogUnavailable, match="conflict"):
            cursor.decode(invalid, company, source, 7, NOW)


def test_cursor_preserves_fractional_expiry() -> None:
    company, source = uuid7(), uuid7()
    cursor = CatalogCursor("test-cursor-secret-" + "m" * 32)
    deadline = NOW + timedelta(microseconds=750000)
    token = cursor.encode(company, source, 1, "a" * 64, deadline)
    assert (
        cursor.decode(token, company, source, 1, NOW + timedelta(microseconds=500000))
        == "a" * 64
    )
    with pytest.raises(CatalogUnavailable, match="conflict"):
        cursor.decode(token, company, source, 1, deadline)
