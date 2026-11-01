import json
from datetime import datetime

from product_research_ozon.domain.month import (
    PAGE_SIZE,
    CollectionFailure,
    Snapshot,
    StoredPage,
)


def manifest_body(
    value: Snapshot, pages: tuple[StoredPage, ...], completed_at: datetime
) -> bytes:
    if (
        value.total is None
        or value.total != value.cursor
        or not pages
        or len(pages) != value.pages
        or sum(p.byte_size for p in pages) != value.byte_size
    ):
        raise CollectionFailure("incomplete")
    cursor = 0
    for index, page in enumerate(pages):
        if (
            page.page_no != index
            or page.start != cursor
            or page.total != value.total
            or page.end != min(cursor + PAGE_SIZE, value.total)
        ):
            raise CollectionFailure("incomplete")
        cursor = page.end
    if cursor != value.total:
        raise CollectionFailure("incomplete")
    parameters = value.parameters
    return json.dumps(
        {
            "schema_version": 1,
            "company_id": str(value.company_id),
            "source_connection_id": str(value.source_id),
            "snapshot_id": str(value.snapshot_id),
            "category_path": parameters.category_path,
            "date_from": parameters.date_from.isoformat(),
            "date_to": parameters.date_to.isoformat(),
            "days": parameters.days,
            "partial": parameters.partial,
            "fbs": True,
            "currency": "RUB",
            "source": "MPStats",
            "endpoint": "/api/analytics/v1/oz/category/items",
            "filter_model": {},
            "sort_model": [
                {"colId": "revenue", "sort": "desc"},
                {"colId": "id", "sort": "asc"},
            ],
            "credential_generation": value.generation,
            "created_at": value.created_at.isoformat(),
            "completed_at": completed_at.isoformat(),
            "display_name": f"{parameters.category_path.rsplit('/', 1)[-1]}_{parameters.date_from:%d.%m.%Y}-{parameters.date_to:%d.%m.%Y}",
            "rows": value.cursor,
            "total": value.total,
            "byte_size": value.byte_size,
            "warning": "empty_category_or_renamed" if value.total == 0 else None,
            "pages": [
                {
                    "page_no": p.page_no,
                    "start_row": p.start,
                    "end_row": p.end,
                    "rows": p.end - p.start,
                    "sha256": p.sha256,
                    "bytes": p.byte_size,
                }
                for p in pages
            ],
        },
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
    ).encode()
