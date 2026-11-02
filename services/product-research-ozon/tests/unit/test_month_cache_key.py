from datetime import date
from uuid import uuid7

import pytest

from product_research_ozon.domain.month_cache import MonthCacheKey


def test_key_is_scoped_and_covers_semantics_and_rejects_partial() -> None:
    company, source = uuid7(), uuid7()
    key = MonthCacheKey(
        company, source, "Категория", date(2026, 1, 1), date(2026, 1, 31)
    )
    assert (
        key.digest
        == MonthCacheKey(
            company, source, "Категория", date(2026, 1, 1), date(2026, 1, 31)
        ).digest
    )
    from dataclasses import replace

    for changed in (
        replace(key, company_id=uuid7()),
        replace(key, source_id=uuid7()),
        replace(key, category_path="Другая"),
        replace(key, date_from=date(2026, 2, 1), date_to=date(2026, 2, 28)),
        replace(key, fbs=False),
        replace(key, currency="USD"),
        replace(key, version="2"),
        replace(key, semantics="different"),
    ):
        assert key.digest != changed.digest
    with pytest.raises(ValueError):
        MonthCacheKey(company, source, "Категория", date(2026, 1, 1), date(2026, 1, 30))
