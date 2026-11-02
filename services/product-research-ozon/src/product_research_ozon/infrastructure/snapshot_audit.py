"""Read-only recovery proof: exact journal/manifest/object agreement."""

from dataclasses import dataclass
from uuid import UUID

from product_research_ozon.domain.month import CollectionFailure, SnapshotStatus
from product_research_ozon.domain.storage import ManifestRef, PageRef
from product_research_ozon.infrastructure.config import Settings
from product_research_ozon.infrastructure.snapshot_store import SnapshotStore
from product_research_ozon.infrastructure.storage import RawStorage
from product_research_ozon.snapshots.manifest import manifest_body


@dataclass(frozen=True)
class AuditResult:
    pages: int
    rows: int
    raw_bytes: int


class SnapshotAuditor:
    def __init__(self, settings: Settings) -> None:
        self._store = SnapshotStore(settings.database_url)
        self._storage = RawStorage(settings)

    def verify(
        self, company_id: UUID, source_id: UUID, snapshot_id: UUID
    ) -> AuditResult:
        value = self._store.get(company_id, source_id, snapshot_id)
        if value.status != SnapshotStatus.READY:
            raise CollectionFailure("not_found")
        if value.manifest_sha256 is None or value.completed_at is None:
            raise CollectionFailure("corrupt_raw")
        pages = self._store.pages(company_id, source_id, snapshot_id)
        manifest = self._storage.read_object(
            company_id,
            ManifestRef(company_id, source_id, snapshot_id, value.manifest_sha256),
        )
        try:
            expected = manifest_body(value, pages, value.completed_at)
        except CollectionFailure as error:
            if error.code == "incomplete":
                raise CollectionFailure("corrupt_raw") from None
            raise
        if len(manifest) != value.manifest_size or manifest != expected:
            raise CollectionFailure("corrupt_raw")
        cursor, byte_size = 0, 0
        for number, page in enumerate(pages):
            if (
                page.page_no != number
                or page.start != cursor
                or page.total != value.total
            ):
                raise CollectionFailure("corrupt_raw")
            body = self._storage.read_object(
                company_id,
                PageRef(company_id, source_id, snapshot_id, number, page.sha256),
            )
            if len(body) != page.byte_size:
                raise CollectionFailure("corrupt_raw")
            cursor, byte_size = page.end, byte_size + len(body)
        if (len(pages), cursor, byte_size) != (
            value.pages,
            value.total,
            value.byte_size,
        ):
            raise CollectionFailure("corrupt_raw")
        return AuditResult(len(pages), cursor, byte_size)
