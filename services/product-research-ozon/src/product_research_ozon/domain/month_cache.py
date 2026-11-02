"""Semantic identity of reusable closed-month raw data (ADR-050)."""

import hashlib
import json
from dataclasses import dataclass
from datetime import date
from uuid import UUID

from product_research_ozon.domain.month import MonthParameters, identity


@dataclass(frozen=True)
class MonthCacheKey:
    company_id: UUID
    source_id: UUID
    category_path: str
    date_from: date
    date_to: date
    fbs: bool = True
    currency: str = "RUB"
    version: str = "1"
    semantics: str = "oz/category/items;revenue:desc,id:asc;filter:{};newness:all"

    def __post_init__(self) -> None:
        identity(self.company_id)
        identity(self.source_id)
        MonthParameters(self.category_path, self.date_from, self.date_to, False)

    def payload(self) -> dict[str, object]:
        return {
            "company_id": str(self.company_id),
            "source_id": str(self.source_id),
            "category_path": self.category_path,
            "date_from": self.date_from.isoformat(),
            "date_to": self.date_to.isoformat(),
            "fbs": self.fbs,
            "currency": self.currency,
            "version": self.version,
            "semantics": self.semantics,
        }

    @property
    def digest(self) -> str:
        return hashlib.sha256(
            json.dumps(
                self.payload(),
                ensure_ascii=False,
                sort_keys=True,
                separators=(",", ":"),
            ).encode()
        ).hexdigest()
