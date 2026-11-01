import logging
import time
from collections.abc import Callable
from datetime import datetime
from typing import Protocol
from uuid import UUID, uuid7

from product_research_ozon.domain.catalog import CatalogUnavailable, Category, utc_now
from product_research_ozon.infrastructure.catalog_cursor import CatalogCursor
from product_research_ozon.infrastructure.catalog_store import CatalogStore
from product_research_ozon.infrastructure.observability import Event, emit


class CatalogSource(Protocol):
    def fetch(self) -> tuple[Category, ...]: ...


class CategoryCatalog:
    def __init__(
        self,
        store: CatalogStore,
        source: CatalogSource,
        source_id: UUID,
        generation: str,
        rpm: int,
        cursor: CatalogCursor,
        clock: Callable[[], datetime] = utc_now,
    ) -> None:
        if (
            source_id.version != 7
            or not 1 <= rpm <= 1000
            or not generation
            or len(generation) > 64
        ):
            raise ValueError("invalid_catalog_configuration")
        (
            self._store,
            self._source,
            self._source_id,
            self._generation,
            self._rpm,
            self._cursor,
            self._clock,
        ) = store, source, source_id, generation, rpm, cursor, clock

    @property
    def source_id(self) -> UUID:
        return self._source_id

    def page(
        self,
        company_id: UUID,
        limit: int,
        cursor: str | None,
        trace_id: str | None = None,
    ) -> dict[str, object]:
        if company_id.version != 7 or not 1 <= limit <= 100:
            raise CatalogUnavailable("contract_violation")
        version = self._store.metadata(company_id, self._source_id)
        now = self._clock()
        if cursor is None and (version is None or not version.is_fresh(now)):
            owner = uuid7()
            if not self._store.claim(company_id, self._source_id, owner, now):
                raise CatalogUnavailable()
            started = time.monotonic()
            acquired = False
            failure: CatalogUnavailable | None = None
            try:
                if not self._store.provider_claim(
                    self._source_id, owner, self._generation, self._rpm, now
                ):
                    raise CatalogUnavailable("provider_paused")
                acquired = True
                categories = self._source.fetch()
                version = self._store.publish(
                    company_id, self._source_id, owner, categories, self._clock()
                )
                emit(
                    logging.getLogger("product-research-ozon"),
                    Event(
                        company_id=str(company_id),
                        source_connection_id=str(self._source_id),
                        trace_id=trace_id,
                        resource_type="category_catalog",
                        outcome="refreshed",
                        duration_ms=int((time.monotonic() - started) * 1000),
                    ),
                )
            except CatalogUnavailable as exc:
                failure = exc
                emit(
                    logging.getLogger("product-research-ozon"),
                    Event(
                        company_id=str(company_id),
                        source_connection_id=str(self._source_id),
                        trace_id=trace_id,
                        resource_type="category_catalog",
                        outcome="retry",
                        error_class=exc.code,
                        duration_ms=int((time.monotonic() - started) * 1000),
                    ),
                    logging.WARNING,
                )
                self._store.fail(company_id, self._source_id, owner, exc, self._clock())
                raise
            finally:
                if acquired:
                    self._store.provider_release(
                        self._source_id, owner, self._generation, failure
                    )
        if version is None or not version.is_fresh(self._clock()):
            raise CatalogUnavailable("conflict" if cursor else "not_available")
        after = (
            ""
            if cursor is None
            else self._cursor.decode(
                cursor, company_id, self._source_id, version.revision, self._clock()
            )
        )
        rows = self._store.page(
            company_id, self._source_id, version.revision, after, limit, self._clock()
        )
        more = len(rows) > limit
        selected = rows[:limit]
        next_cursor = (
            self._cursor.encode(
                company_id,
                self._source_id,
                version.revision,
                selected[-1].key,
                version.expires_at,
            )
            if more
            else None
        )
        return {
            "schema_version": "1",
            "company_id": str(company_id),
            "revision": version.revision,
            "generated_at": version.generated_at.isoformat(),
            "items": [{"path": entry.path} for entry in selected],
            "next_cursor": next_cursor,
        }
