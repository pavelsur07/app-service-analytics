import hashlib
from datetime import UTC, date, datetime
from decimal import Decimal

import pytest

from product_research_ozon.domain.month import (
    CollectionFailure,
    CollectionLimits,
    MonthParameters,
    parse_page,
)

NOW = datetime(2026, 11, 1, tzinfo=UTC)


def test_calendar_is_full_month_or_explicit_current_month_to_yesterday() -> None:
    full = MonthParameters.create("Категория/Товар", 2024, 2, NOW)
    assert (full.date_from, full.date_to, full.days, full.partial) == (
        date(2024, 2, 1),
        date(2024, 2, 29),
        29,
        False,
    )
    for year, month, partial in [
        (2026, 12, False),
        (2026, 11, False),
        (2026, 11, True),
    ]:
        with pytest.raises(ValueError):
            MonthParameters.create("Категория", year, month, NOW, partial)
    current = MonthParameters.create(
        "Категория", 2026, 11, datetime(2026, 11, 2, tzinfo=UTC), True
    )
    assert (
        current.date_to == date(2026, 11, 1) and current.partial and current.days == 1
    )
    # Calendar policy is Europe/Moscow, not host UTC.
    near_midnight = MonthParameters.create(
        "Категория", 2026, 11, datetime(2026, 11, 1, 21, tzinfo=UTC), True
    )
    assert near_midnight == current
    with pytest.raises(ValueError):
        MonthParameters.create("Категория", 2026, 1, datetime(2026, 11, 2))


def test_parser_preserves_bytes_decimal_unknown_fields_and_zero_sales() -> None:
    raw = b'{ "startRow":0,"endRow":2,"total":2,"data":[{"id":7,"sales":0,"balance":0,"revenue":0.10,"future":{"x":null}},{"id":8,"revenue":9007199254740993.21}] }'
    page = parse_page(raw, 0, 500)
    assert page.body == raw
    assert page.ids == (7, 8) and page.total == 2 and page.end == 2
    assert page.sha256 == hashlib.sha256(raw).hexdigest()
    import json

    parsed = json.loads(page.body, parse_float=Decimal)
    assert parsed["data"][1]["revenue"] == Decimal("9007199254740993.21")
    assert parsed["data"][0]["sales"] == 0


@pytest.mark.parametrize(
    "raw",
    [
        b"{}",
        b"[]",
        b"null",
        b'{"startRow":0,"endRow":0,"total":true,"data":[]}',
        b'{"startRow":0,"endRow":1,"total":2,"data":[{"id":1}]}',
        b'{"startRow":1,"endRow":1,"total":1,"data":[]}',
        b'{"startRow":0,"endRow":0,"total":1,"data":[]}',
        b'{"startRow":0,"endRow":1,"total":1,"data":[{}]}',
        b'{"startRow":0,"endRow":1,"total":1,"data":[{"id":true}]}',
        b'{"startRow":0,"endRow":1,"total":1,"data":[{"id":"1"}]}',
        b'{"startRow":0,"endRow":1,"total":1,"data":[{"id":-1}]}',
        b'{"startRow":0,"endRow":2,"total":2,"data":[{"id":1},{"id":1}]}',
        b'{"startRow":0,"endRow":1,"total":1,"data":[{"id":1,"x":NaN}]}',
        b'{"startRow":0,"startRow":0,"endRow":0,"total":0,"data":[]}',
    ],
)
def test_invalid_page_cannot_be_published(raw: bytes) -> None:
    with pytest.raises(CollectionFailure):
        parse_page(raw, 0, 500)


def test_empty_category_and_bounds() -> None:
    page = parse_page(b'{"startRow":0,"endRow":0,"total":0,"data":[]}', 0, 500)
    assert page.total == 0 and page.ids == ()
    with pytest.raises(CollectionFailure):
        parse_page(b"x" * (16 * 1024 * 1024 + 1), 0, 500)
    with pytest.raises(ValueError):
        CollectionLimits(0, 1, 1024)
