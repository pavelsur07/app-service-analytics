import asyncio
import json
from collections.abc import AsyncIterator
from datetime import UTC, datetime, timedelta

import httpx
import pytest

from product_research_ozon.domain.month import CollectionFailure, MonthParameters
from product_research_ozon.infrastructure.mpstats_items import URL, MpstatsItems

TOKEN = "synthetic-provider-secret-" + "x" * 32
NOW = datetime(2026, 11, 1, tzinfo=UTC)
PARAMETERS = MonthParameters.create("Категория/Товар", 2026, 1, NOW)


def test_exact_request_and_unmodified_source_bytes() -> None:
    body = b'{ "startRow":500,"endRow":500,"total":500,"data":[] }'

    def handler(request: httpx.Request) -> httpx.Response:
        assert request.method == "POST" and str(request.url).split("?")[0] == URL
        assert request.url.params == httpx.QueryParams(
            {
                "path": "Категория/Товар",
                "d1": "2026-01-01",
                "d2": "2026-01-31",
                "fbs": "1",
                "currency": "RUB",
            }
        )
        assert request.headers["X-Mpstats-TOKEN"] == TOKEN and TOKEN not in str(
            request.url
        )
        assert json.loads(request.content) == {
            "startRow": 500,
            "endRow": 1000,
            "filterModel": {},
            "sortModel": [
                {"colId": "revenue", "sort": "desc"},
                {"colId": "id", "sort": "asc"},
            ],
        }
        return httpx.Response(200, content=body)

    assert (
        MpstatsItems(TOKEN, httpx.MockTransport(handler)).fetch(PARAMETERS, 500, 1000)
        == body
    )


@pytest.mark.parametrize(
    "status,code",
    [
        (401, "authorization_failed"),
        (403, "authorization_failed"),
        (429, "rate_limited"),
        (202, "report_pending"),
        (500, "source_unavailable"),
        (302, "source_unavailable"),
    ],
)
def test_errors_are_static_and_do_not_disclose_response_or_key(
    status: int, code: str
) -> None:
    calls = 0

    def handler(request: httpx.Request) -> httpx.Response:
        nonlocal calls
        calls += 1
        return httpx.Response(
            status,
            headers={"Retry-After": "120", "Location": "https://evil.invalid/"},
            content=(TOKEN + "unsafe raw response").encode(),
        )

    with pytest.raises(CollectionFailure) as caught:
        MpstatsItems(TOKEN, httpx.MockTransport(handler), lambda: NOW).fetch(
            PARAMETERS, 0, 500
        )
    assert calls == 1 and str(caught.value) == code
    assert TOKEN not in str(caught.value) and "unsafe" not in str(caught.value)
    if status in (202, 429):
        assert caught.value.retry_at == NOW + timedelta(seconds=120)


def test_retry_after_is_measured_from_response_time_and_http_date_is_supported() -> (
    None
):
    now = NOW

    def handler(request: httpx.Request) -> httpx.Response:
        nonlocal now
        now += timedelta(seconds=10)
        return httpx.Response(429, headers={"Retry-After": "60"})

    with pytest.raises(CollectionFailure) as caught:
        MpstatsItems(TOKEN, httpx.MockTransport(handler), lambda: now).fetch(
            PARAMETERS, 0, 500
        )
    assert caught.value.retry_at == NOW + timedelta(seconds=70)

    def dated(request: httpx.Request) -> httpx.Response:
        return httpx.Response(
            202, headers={"Retry-After": "Sun, 01 Nov 2026 00:03:00 GMT"}
        )

    with pytest.raises(CollectionFailure) as caught:
        MpstatsItems(TOKEN, httpx.MockTransport(dated), lambda: NOW).fetch(
            PARAMETERS, 0, 500
        )
    assert caught.value.retry_at == NOW + timedelta(minutes=3)


def test_network_and_oversize_are_bounded() -> None:
    def network(request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError(TOKEN, request=request)

    with pytest.raises(CollectionFailure, match="source_unavailable"):
        MpstatsItems(TOKEN, httpx.MockTransport(network)).fetch(PARAMETERS, 0, 500)

    def oversize(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, content=b"x" * (16 * 1024 * 1024 + 1))

    with pytest.raises(CollectionFailure, match="invalid_source"):
        MpstatsItems(TOKEN, httpx.MockTransport(oversize)).fetch(PARAMETERS, 0, 500)


class Trickle(httpx.AsyncByteStream):
    async def __aiter__(self) -> AsyncIterator[bytes]:
        while True:
            yield b"x"
            await asyncio.sleep(0.005)


def test_total_deadline_includes_slow_response_body() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, stream=Trickle())

    with pytest.raises(CollectionFailure, match="source_unavailable"):
        MpstatsItems(TOKEN, httpx.MockTransport(handler), deadline_seconds=0.03).fetch(
            PARAMETERS, 0, 500
        )
