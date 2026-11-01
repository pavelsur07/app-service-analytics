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
