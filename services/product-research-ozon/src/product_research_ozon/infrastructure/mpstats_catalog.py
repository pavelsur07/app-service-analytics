import asyncio
import json
from collections.abc import Callable
from datetime import UTC, datetime, timedelta
from decimal import Decimal
from email.utils import parsedate_to_datetime
from typing import Never
from zoneinfo import ZoneInfo

import httpx

from product_research_ozon.domain.catalog import (
    MAX_BODY_BYTES,
    MAX_CATEGORIES,
    CatalogUnavailable,
    Category,
    utc_now,
)

URL = "https://mpstats.io/api/analytics/v1/oz/category/list"


def invalid_constant(value: str) -> Never:
    raise ValueError("invalid_json_constant")


def parse_categories(body: bytes) -> tuple[Category, ...]:
    if len(body) > MAX_BODY_BYTES:
        raise CatalogUnavailable("invalid_source")
    try:
        data: object = json.loads(
            body, parse_float=Decimal, parse_constant=invalid_constant
        )
        if not isinstance(data, list) or len(data) > MAX_CATEGORIES:
            raise ValueError
        result: list[Category] = []
        seen: set[str] = set()
        for value in data:
            if not isinstance(value, dict) or not isinstance(value.get("name"), str):
                raise CatalogUnavailable("invalid_source")
            source_id = value.get("id")
            if source_id is not None and (
                isinstance(source_id, bool) or not isinstance(source_id, (str, int))
            ):
                raise ValueError
            category = Category(
                value["name"], None if source_id is None else str(source_id)
            )
            if category.key in seen:
                raise ValueError
            seen.add(category.key)
            result.append(category)
        return tuple(result)
    except ValueError, RecursionError, UnicodeError:
        raise CatalogUnavailable("invalid_source") from None


def retry_deadline(value: str | None, now: datetime) -> datetime:
    try:
        if value is not None and value.isascii() and value.isdecimal():
            try:
                return now + timedelta(seconds=int(value))
            except ValueError, OverflowError:
                return datetime.max.replace(tzinfo=UTC)
        if value:
            target = parsedate_to_datetime(value)
            if target.tzinfo is not None:
                return max(now, target)
    except ValueError, OverflowError:
        pass
    return now + timedelta(minutes=1)


class MpstatsCatalog:
    def __init__(
        self,
        token: str,
        transport: httpx.AsyncBaseTransport | None = None,
        clock: Callable[[], datetime] = utc_now,
        deadline_seconds: float = 15.0,
    ) -> None:
        if not 0 < deadline_seconds <= 15:
            raise ValueError("invalid_catalog_deadline")
        self._token, self._transport, self._clock, self._deadline = (
            token,
            transport,
            clock,
            deadline_seconds,
        )

    def fetch(self) -> tuple[Category, ...]:
        # The port is synchronous (FastAPI sync routes run in worker threads).
        # An async transport provides a total deadline, including trickling bodies.
        return asyncio.run(self._fetch())

    async def _fetch(self) -> tuple[Category, ...]:
        now = self._clock()
        date = (
            (now.astimezone(ZoneInfo("Europe/Moscow")) - timedelta(days=1))
            .date()
            .isoformat()
        )
        try:
            async with (
                asyncio.timeout(self._deadline),
                httpx.AsyncClient(
                    timeout=httpx.Timeout(10, connect=2),
                    follow_redirects=False,
                    trust_env=False,
                    transport=self._transport,
                ) as client,
                client.stream(
                    "POST",
                    URL,
                    params={"date": date, "fbs": "1", "currency": "RUB"},
                    headers={"X-Mpstats-TOKEN": self._token},
                    json={},
                    follow_redirects=False,
                ) as response,
            ):
                if response.status_code in (401, 403):
                    raise CatalogUnavailable("authorization_failed")
                if response.status_code in (429, 202):
                    raise CatalogUnavailable(
                        "rate_limited",
                        retry_deadline(
                            response.headers.get("Retry-After"), self._clock()
                        ),
                    )
                if response.status_code != 200:
                    raise CatalogUnavailable(
                        "source_unavailable", self._clock() + timedelta(minutes=1)
                    )
                body = bytearray()
                async for chunk in response.aiter_bytes(chunk_size=65536):
                    body.extend(chunk)
                    if len(body) > MAX_BODY_BYTES:
                        raise CatalogUnavailable(
                            "invalid_source", self._clock() + timedelta(minutes=1)
                        )
                return parse_categories(bytes(body))
        except httpx.HTTPError, TimeoutError:
            raise CatalogUnavailable(
                "source_unavailable", self._clock() + timedelta(minutes=1)
            ) from None
