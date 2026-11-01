import re
from collections.abc import Mapping
from dataclasses import dataclass, field
from urllib.parse import urlsplit
from uuid import UUID


@dataclass(frozen=True)
class CatalogSettings:
    source_id: UUID
    generation: int
    rpm: int
    token: str = field(repr=False)
    cursor_secret: str = field(repr=False)

    @classmethod
    def from_mapping(cls, values: Mapping[str, str]) -> CatalogSettings | None:
        flag = values.get("catalog_enabled", "false")
        if flag == "false":
            return None
        if flag != "true":
            raise ValueError("invalid_catalog_configuration")
        try:
            source = UUID(values["catalog_source_id"])
            generation = values["catalog_generation"]
            raw_rpm = values["catalog_rpm"]
            token = values["mpstats_api_token"]
            secret = values["catalog_cursor_secret"]
            if (
                source.version != 7
                or str(source) != values["catalog_source_id"]
                or not re.fullmatch(r"[1-9][0-9]{0,9}", generation)
                or int(generation) > 2147483647
                or not re.fullmatch(r"[0-9]{1,4}", raw_rpm)
                or not 1 <= int(raw_rpm) <= 1000
            ):
                raise ValueError
            if any(
                not re.fullmatch(r"[!-~]{32,512}", value) for value in (token, secret)
            ):
                raise ValueError
        except ValueError, KeyError:
            raise ValueError("invalid_catalog_configuration") from None
        return cls(source, int(generation), int(raw_rpm), token, secret)


@dataclass(frozen=True)
class Settings:
    database_url: str = field(repr=False)
    inbound_token: str = field(repr=False)
    outbound_token: str = field(repr=False)
    s3_endpoint: str
    s3_bucket: str
    s3_access_key: str = field(repr=False)
    s3_secret_key: str = field(repr=False)

    catalog: CatalogSettings | None = None

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
                not re.fullmatch(r"[!-~]{32,256}", token)
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
        return cls(
            **{name: values[name] for name in required},
            catalog=CatalogSettings.from_mapping(values),
        )
