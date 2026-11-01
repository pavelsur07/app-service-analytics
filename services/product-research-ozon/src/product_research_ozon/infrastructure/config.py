from collections.abc import Mapping
from dataclasses import dataclass, field
from urllib.parse import urlsplit


@dataclass(frozen=True)
class Settings:
    database_url: str = field(repr=False)
    inbound_token: str = field(repr=False)
    outbound_token: str = field(repr=False)
    s3_endpoint: str
    s3_bucket: str
    s3_access_key: str = field(repr=False)
    s3_secret_key: str = field(repr=False)

    @classmethod
    def from_mapping(cls, values: Mapping[str, str]) -> Settings:
        required = (
            "database_url",
            "inbound_token",
            "outbound_token",
            "s3_endpoint",
            "s3_bucket",
            "s3_access_key",
            "s3_secret_key",
        )
        if any(not values.get(name) for name in required):
            raise ValueError("missing_service_configuration")
        inbound, outbound = values["inbound_token"], values["outbound_token"]
        if (
            any(
                len(token) < 32
                or len(token) > 256
                or not token.isascii()
                or any(c.isspace() for c in token)
                for token in (inbound, outbound)
            )
            or inbound == outbound
        ):
            raise ValueError("invalid_directional_tokens")
        if values.get("worker_enabled", "false") != "false":
            raise ValueError("worker_not_available_in_stage1")
        try:
            database = urlsplit(values["database_url"])
            endpoint = urlsplit(values["s3_endpoint"])
            if (
                database.scheme not in ("postgresql", "postgres")
                or not database.hostname
                or database.path
                not in ("/product_research_ozon", "/product_research_ozon_test")
            ):
                raise ValueError
            if (
                endpoint.scheme not in ("http", "https")
                or not endpoint.hostname
                or endpoint.username
                or endpoint.password
                or endpoint.query
                or endpoint.fragment
                or endpoint.path not in ("", "/")
            ):
                raise ValueError
        except ValueError:
            raise ValueError("invalid_service_endpoint") from None
        return cls(**{name: values[name] for name in required})
