import asyncio
from collections.abc import Callable
from datetime import datetime, timedelta
from uuid import UUID, uuid7

import httpx
import psycopg

from product_research_ozon.domain.catalog import CatalogUnavailable, utc_now
from product_research_ozon.domain.month import (
    MAX_PAGE_BYTES,
    CollectionFailure,
    MonthParameters,
)
from product_research_ozon.infrastructure.catalog_store import CatalogStore
from product_research_ozon.infrastructure.mpstats_catalog import retry_deadline

URL = "https://mpstats.io/api/analytics/v1/oz/category/items"


class MpstatsItems:
    def __init__(
        self,
        token: str,
        transport: httpx.AsyncBaseTransport | None = None,
        clock: Callable[[], datetime] = utc_now,
        deadline_seconds: float = 15.0,
    ) -> None:
        if not 0 < deadline_seconds <= 15:
            raise ValueError("invalid_source_deadline")
        self._token, self._transport, self._clock, self._deadline = (
            token,
            transport,
            clock,
            deadline_seconds,
        )

    def fetch(self, parameters: MonthParameters, start: int, end: int) -> bytes:
        return asyncio.run(self._fetch(parameters, start, end))

    async def _fetch(self, parameters: MonthParameters, start: int, end: int) -> bytes:
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
                    params={
                        "path": parameters.category_path,
                        "d1": parameters.date_from.isoformat(),
                        "d2": parameters.date_to.isoformat(),
                        "fbs": "1",
                        "currency": "RUB",
                    },
                    headers={"X-Mpstats-TOKEN": self._token},
                    json={
                        "startRow": start,
                        "endRow": end,
                        "filterModel": {},
                        "sortModel": [
                            {"colId": "revenue", "sort": "desc"},
                            {"colId": "id", "sort": "asc"},
                        ],
                    },
                ) as response,
            ):
                if response.status_code in (401, 403):
                    raise CollectionFailure("authorization_failed")
                if response.status_code in (202, 429):
                    raise CollectionFailure(
                        "report_pending"
                        if response.status_code == 202
                        else "rate_limited",
                        retry_deadline(
                            response.headers.get("Retry-After"), self._clock()
                        ),
                    )
                if response.status_code != 200:
                    raise CollectionFailure(
                        "source_unavailable", self._clock() + timedelta(minutes=1)
                    )
                body = bytearray()
                async for chunk in response.aiter_bytes(chunk_size=65536):
                    body.extend(chunk)
                    if len(body) > MAX_PAGE_BYTES:
                        raise CollectionFailure("invalid_source")
                return bytes(body)
        except httpx.HTTPError, TimeoutError:
            raise CollectionFailure(
                "source_unavailable", self._clock() + timedelta(minutes=1)
            ) from None


class GatedItems:
    """One conservative server gate shared with catalog; no sleep or held SQL transaction."""

    def __init__(
        self,
        gate: CatalogStore,
        client: MpstatsItems,
        source_id: UUID,
        generation: int,
        rpm: int,
        clock: Callable[[], datetime] = utc_now,
    ) -> None:
        (
            self._gate,
            self._client,
            self._source,
            self.generation,
            self._rpm,
            self._clock,
        ) = gate, client, source_id, generation, rpm, clock
        if (
            type(generation) is not int
            or not 1 <= generation <= 2147483647
            or type(rpm) is not int
            or not 1 <= rpm <= 1000
        ):
            raise ValueError("invalid_source_configuration")

    def activate(self) -> None:
        try:
            if not self._gate.activate_generation(
                self._source, self.generation, self._clock()
            ):
                raise CollectionFailure("obsolete_generation")
        except psycopg.Error:
            raise CollectionFailure("database_unavailable") from None

    def fetch(
        self,
        company_id: UUID,
        source_id: UUID,
        parameters: MonthParameters,
        start: int,
        end: int,
    ) -> bytes:
        try:
            return self._guarded_fetch(company_id, source_id, parameters, start, end)
        except psycopg.Error:
            raise CollectionFailure("database_unavailable") from None

    def _guarded_fetch(
        self,
        company_id: UUID,
        source_id: UUID,
        parameters: MonthParameters,
        start: int,
        end: int,
    ) -> bytes:
        if source_id != self._source:
            raise CollectionFailure("conflict")
        if not self._gate.generation_current(source_id, self.generation):
            raise CollectionFailure("obsolete_generation")
        owner = uuid7()
        if not self._gate.provider_claim(
            source_id, owner, self.generation, self._rpm, self._clock()
        ):
            # SQL returns the authoritative cooldown/auth state, not a guessed one-second retry.
            raise self._gate_failure(source_id)
        failure: CollectionFailure | None = None
        try:
            return self._client.fetch(parameters, start, end)
        except CollectionFailure as error:
            failure = error
            raise
        finally:
            self._gate.provider_release(
                source_id,
                owner,
                self.generation,
                None
                if failure is None
                else CatalogUnavailable(
                    "authorization_failed"
                    if failure.code == "authorization_failed"
                    else "rate_limited"
                    if failure.retry_at is not None
                    else "source_unavailable",
                    failure.retry_at,
                ),
            )

    def _gate_failure(self, source_id: UUID) -> CollectionFailure:
        code, retry_at = self._gate.provider_status(source_id, self.generation)
        return CollectionFailure(code, retry_at)
