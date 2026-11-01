from datetime import UTC, datetime, timedelta

import httpx
import pytest

from product_research_ozon.domain.catalog import MAX_BODY_BYTES, CatalogUnavailable
from product_research_ozon.infrastructure.mpstats_catalog import (
    MpstatsCatalog,
    retry_deadline,
)

NOW = datetime(2026, 11, 1, 12, tzinfo=UTC)
TOKEN = "private-mpstats-test-" + "t" * 32


def test_source_request_is_fixed_authenticated_and_decimal_safe() -> None:
    def respond(request: httpx.Request) -> httpx.Response:
        assert request.method == "POST"
        assert request.url.host == "mpstats.io"
        assert request.url.path == "/api/analytics/v1/oz/category/list"
        assert request.headers["X-Mpstats-TOKEN"] == TOKEN
        assert TOKEN not in str(request.url)
        assert request.url.params["fbs"] == "1"
        assert request.url.params["currency"] == "RUB"
        assert request.url.params["date"] == "2026-10-31"
        return httpx.Response(
            200, content=b'[{"name":"Catalog/Leaf","revenue":9007199254740993.99}]'
        )

    categories = MpstatsCatalog(
        TOKEN, httpx.MockTransport(respond), lambda: NOW
    ).fetch()
    assert [category.path for category in categories] == ["Catalog/Leaf"]


@pytest.mark.parametrize("status", [401, 403, 429, 202, 500, 302])
def test_source_errors_are_safe_and_redirects_are_not_followed(status: int) -> None:
    calls = 0

    def respond(request: httpx.Request) -> httpx.Response:
        nonlocal calls
        calls += 1
        return httpx.Response(
            status,
            text=TOKEN,
            headers={"Retry-After": "120", "Location": "https://attacker.invalid/"},
        )

    with pytest.raises(CatalogUnavailable) as caught:
        MpstatsCatalog(TOKEN, httpx.MockTransport(respond), lambda: NOW).fetch()
    assert calls == 1
    assert TOKEN not in str(caught.value)
    if status in (429, 202):
        assert caught.value.retry_at == NOW + timedelta(seconds=120)
    if status in (401, 403):
        assert caught.value.code == "authorization_failed"


def test_retry_after_http_date_and_long_pause_are_not_shortened() -> None:
    assert retry_deadline("Tue, 03 Nov 2026 12:00:00 GMT", NOW) == NOW + timedelta(
        days=2
    )
    assert retry_deadline("1000000000", NOW) == NOW + timedelta(seconds=1000000000)
    assert retry_deadline("9" * 40, NOW) == datetime.max.replace(tzinfo=UTC)


def test_malformed_oversize_and_timeout_are_not_publishable() -> None:
    for body in [
        b"not-json",
        b'[{"name":"Leaf","revenue":NaN}]',
        b"x" * (MAX_BODY_BYTES + 1),
    ]:
        with pytest.raises(CatalogUnavailable):
            MpstatsCatalog(
                TOKEN,
                httpx.MockTransport(
                    lambda request, value=body: httpx.Response(200, content=value)
                ),
            ).fetch()

    def timeout(request: httpx.Request) -> httpx.Response:
        raise httpx.ReadTimeout(TOKEN)

    with pytest.raises(CatalogUnavailable) as caught:
        MpstatsCatalog(TOKEN, httpx.MockTransport(timeout)).fetch()
    assert TOKEN not in str(caught.value)


def test_catalog_configuration_requires_opt_in_and_hides_credentials() -> None:
    from uuid import uuid7

    from product_research_ozon.infrastructure.config import CatalogSettings

    assert CatalogSettings.from_mapping({}) is None
    with pytest.raises(ValueError, match="invalid_catalog_configuration"):
        CatalogSettings.from_mapping({"catalog_enabled": "true"})
    values = {
        "catalog_enabled": "true",
        "catalog_source_id": str(uuid7()),
        "catalog_generation": "1",
        "catalog_rpm": "60",
        "mpstats_api_token": TOKEN,
        "catalog_cursor_secret": "private-cursor-" + "c" * 32,
    }
    configured = CatalogSettings.from_mapping(values)
    assert configured is not None and configured.rpm == 60
    assert TOKEN not in repr(configured)
    assert values["catalog_cursor_secret"] not in repr(configured)


def test_total_deadline_stops_a_trickling_response() -> None:
    import asyncio
    from collections.abc import AsyncIterator

    class Trickle(httpx.AsyncByteStream):
        async def __aiter__(self) -> AsyncIterator[bytes]:
            for _ in range(1000):
                await asyncio.sleep(0.01)
                yield b"x"

    transport = httpx.MockTransport(
        lambda request: httpx.Response(200, stream=Trickle())
    )
    with pytest.raises(CatalogUnavailable, match="source_unavailable"):
        MpstatsCatalog(TOKEN, transport, deadline_seconds=0.03).fetch()


def test_retry_after_starts_when_response_is_received() -> None:
    current = [NOW]

    def respond(request: httpx.Request) -> httpx.Response:
        current[0] = NOW + timedelta(seconds=10)
        return httpx.Response(429, headers={"Retry-After": "120"})

    with pytest.raises(CatalogUnavailable) as caught:
        MpstatsCatalog(TOKEN, httpx.MockTransport(respond), lambda: current[0]).fetch()
    assert caught.value.retry_at == NOW + timedelta(seconds=130)
