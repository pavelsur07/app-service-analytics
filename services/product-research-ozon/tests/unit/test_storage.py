import hashlib
from uuid import UUID

import pytest

from product_research_ozon.domain.storage import PageRef

A = UUID("019a3c00-0000-7000-8000-000000000001")
B = UUID("019a3c00-0000-7000-8000-000000000002")


def test_company_is_a_required_scope() -> None:
    page = PageRef(A, B, A, 0, hashlib.sha256(b"{}").hexdigest())
    assert f"/companies/{A}/" in page.key(A)
    with pytest.raises(ValueError, match="company_scope_mismatch"):
        page.key(B)


@pytest.mark.parametrize(
    "page,sha", [(-1, "a" * 64), (0, "../private"), (True, "a" * 64)]
)
def test_key_segments_are_rejected(page: int, sha: str) -> None:
    with pytest.raises(ValueError, match="invalid_page_reference"):
        PageRef(A, B, A, page, sha).key(A)


def test_readiness_lists_only_research_prefix_in_existing_bucket() -> None:
    from unittest.mock import patch

    from product_research_ozon.infrastructure.config import Settings
    from product_research_ozon.infrastructure.storage import RawStorage

    with patch("product_research_ozon.infrastructure.storage.boto3.client") as client:
        storage = RawStorage(
            Settings.from_mapping(
                {
                    "database_url": "postgresql://test:local@postgres/product_research_ozon_test",
                    "inbound_token": "a" * 64,
                    "outbound_token": "b" * 64,
                    "s3_endpoint": "http://s3:8333",
                    "s3_bucket": "conwix-test",
                    "s3_access_key": "test",
                    "s3_secret_key": "test",
                }
            )
        )
        storage.ready()
        client.return_value.list_objects_v2.assert_called_once_with(
            Bucket="conwix-test",
            Prefix="conwix/svc/product-research-ozon/v1/",
            MaxKeys=1,
        )
        client.return_value.head_bucket.assert_not_called()
        client.return_value.create_bucket.assert_not_called()
