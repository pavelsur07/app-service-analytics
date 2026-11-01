from dataclasses import dataclass
from uuid import UUID


@dataclass(frozen=True)
class PageRef:
    company_id: UUID
    connection_id: UUID
    snapshot_id: UUID
    page_no: int
    sha256: str

    def key(self, company_id: UUID) -> str:
        if company_id != self.company_id:
            raise ValueError("company_scope_mismatch")
        if any(
            value.version != 7
            for value in (self.company_id, self.connection_id, self.snapshot_id)
        ):
            raise ValueError("invalid_object_identity")
        if (
            type(self.page_no) is not int
            or not 0 <= self.page_no <= 1000000
            or len(self.sha256) != 64
            or any(c not in "0123456789abcdef" for c in self.sha256)
        ):
            raise ValueError("invalid_page_reference")
        return f"conwix/svc/product-research-ozon/v1/companies/{company_id}/connections/{self.connection_id}/snapshots/{self.snapshot_id}/pages/{self.page_no}/{self.sha256}.json"
