from dataclasses import dataclass
from datetime import UTC, datetime, timedelta
from hashlib import sha256

TTL = timedelta(hours=24)
MAX_CATEGORIES = 20_000
MAX_BODY_BYTES = 16 * 1024 * 1024


class CatalogUnavailable(Exception):
    def __init__(
        self, code: str = "not_available", retry_at: datetime | None = None
    ) -> None:
        safe_codes = {
            "not_available",
            "contract_violation",
            "conflict",
            "invalid_source",
            "source_unavailable",
            "authorization_failed",
            "rate_limited",
            "lease_lost",
            "provider_paused",
            "invalid_cache",
        }
        self.code = code if code in safe_codes else "catalog_failure"
        super().__init__(self.code)
        self.retry_at = retry_at


@dataclass(frozen=True)
class Category:
    path: str
    source_id: str | None = None

    def __post_init__(self) -> None:
        if not self.path.strip() or len(self.path) > 2048 or "\0" in self.path:
            raise CatalogUnavailable("invalid_source")
        if self.source_id is not None and (
            not self.source_id or len(self.source_id) > 128 or "\0" in self.source_id
        ):
            raise CatalogUnavailable("invalid_source")

    @property
    def key(self) -> str:
        return sha256(self.path.encode("utf-8")).hexdigest()


@dataclass(frozen=True)
class CatalogVersion:
    revision: int
    generated_at: datetime

    def __post_init__(self) -> None:
        if self.revision < 1 or self.generated_at.tzinfo is None:
            raise CatalogUnavailable("invalid_source")

    def is_fresh(self, now: datetime) -> bool:
        return self.generated_at <= now < self.generated_at + TTL

    @property
    def expires_at(self) -> datetime:
        return self.generated_at + TTL


def utc_now() -> datetime:
    return datetime.now(UTC)
