import base64
import binascii
import hashlib
import hmac
import re
import struct
from datetime import UTC, datetime
from uuid import UUID

from product_research_ozon.domain.catalog import CatalogUnavailable


class CatalogCursor:
    def __init__(self, secret: str) -> None:
        if len(secret) < 32 or not secret.isascii():
            raise ValueError("invalid_cursor_secret")
        self._key = hmac.digest(secret.encode("ascii"), b"category-cursor-v1", "sha256")

    def encode(
        self, company: UUID, source: UUID, revision: int, key: str, expires_at: datetime
    ) -> str:
        payload = (
            company.bytes
            + source.bytes
            + struct.pack(">QQ", revision, self._microseconds(expires_at))
            + bytes.fromhex(key)
        )
        signature = hmac.digest(self._key, payload, hashlib.sha256)
        return (
            base64.urlsafe_b64encode(payload + signature).rstrip(b"=").decode("ascii")
        )

    def decode(
        self, token: str, company: UUID, source: UUID, revision: int, now: datetime
    ) -> str:
        try:
            if not re.fullmatch(r"[a-zA-Z0-9_-]{150}", token):
                raise ValueError
            raw = base64.urlsafe_b64decode(token + "==")
            payload, signature = raw[:-32], raw[-32:]
            if (
                base64.urlsafe_b64encode(raw).rstrip(b"=").decode("ascii") != token
                or len(raw) != 112
                or not hmac.compare_digest(
                    signature, hmac.digest(self._key, payload, hashlib.sha256)
                )
            ):
                raise ValueError
            actual_revision, deadline = struct.unpack(">QQ", payload[32:48])
            if (
                payload[:16] != company.bytes
                or payload[16:32] != source.bytes
                or actual_revision != revision
                or self._microseconds(now) >= deadline
            ):
                raise ValueError
            return payload[48:80].hex()
        except ValueError, binascii.Error, struct.error:
            raise CatalogUnavailable("conflict") from None

    @staticmethod
    def _microseconds(value: datetime) -> int:
        delta = value - datetime(1970, 1, 1, tzinfo=UTC)
        return (
            delta.days * 86_400_000_000 + delta.seconds * 1_000_000 + delta.microseconds
        )
