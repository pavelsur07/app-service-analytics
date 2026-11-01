from datetime import datetime
from typing import Protocol
from uuid import UUID

from product_research_ozon.domain.month import (
    CollectionFailure,
    CollectionLimits,
    MonthParameters,
    Snapshot,
    SourcePage,
    StoredPage,
)
from product_research_ozon.domain.storage import ManifestRef, PageRef


class ItemsSource(Protocol):
    generation: int

    def fetch(
        self,
        company_id: UUID,
        source_id: UUID,
        parameters: MonthParameters,
        start: int,
        end: int,
    ) -> bytes: ...


class SnapshotRepository(Protocol):
    def create(
        self,
        company_id: UUID,
        source_id: UUID,
        snapshot_id: UUID,
        parameters: MonthParameters,
        limits: CollectionLimits,
        generation: int,
        now: datetime,
    ) -> Snapshot: ...
    def get(self, company_id: UUID, source_id: UUID, snapshot_id: UUID) -> Snapshot: ...
    def claim(
        self,
        company_id: UUID,
        source_id: UUID,
        snapshot_id: UUID,
        owner: UUID,
        generation: int,
        now: datetime,
    ) -> bool: ...
    def append(
        self,
        company_id: UUID,
        source_id: UUID,
        snapshot_id: UUID,
        owner: UUID,
        page: SourcePage,
    ) -> Snapshot: ...
    def pages(
        self, company_id: UUID, source_id: UUID, snapshot_id: UUID
    ) -> tuple[StoredPage, ...]: ...
    def page(
        self, company_id: UUID, source_id: UUID, snapshot_id: UUID, page_no: int
    ) -> StoredPage: ...
    def finish(
        self,
        company_id: UUID,
        source_id: UUID,
        snapshot_id: UUID,
        owner: UUID,
        sha256: str,
        size: int,
        now: datetime,
    ) -> Snapshot: ...
    def release(
        self,
        company_id: UUID,
        source_id: UUID,
        snapshot_id: UUID,
        owner: UUID,
        failure: CollectionFailure | None = None,
    ) -> None: ...


class SnapshotStorage(Protocol):
    def save_page(self, company_id: UUID, page: PageRef, body: bytes) -> None: ...
    def save_manifest(
        self, company_id: UUID, manifest: ManifestRef, body: bytes
    ) -> None: ...
    def read_object(self, company_id: UUID, ref: PageRef | ManifestRef) -> bytes: ...
