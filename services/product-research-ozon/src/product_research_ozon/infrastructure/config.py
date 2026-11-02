import re
from collections.abc import Mapping
from dataclasses import dataclass, field
from urllib.parse import urlsplit
from uuid import UUID

from product_research_ozon.domain.month import CollectionLimits, identity


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
class CollectionSettings:
    limits: CollectionLimits
    allowed_companies: tuple[UUID, ...]
    worker_enabled: bool

    @classmethod
    def from_mapping(
        cls, values: Mapping[str, str], catalog: CatalogSettings | None
    ) -> CollectionSettings | None:
        flag = values.get("collection_enabled", "false")
        worker = values.get("worker_enabled", "false")
        if flag not in ("false", "true") or worker not in ("false", "true"):
            raise ValueError("invalid_collection_configuration")
        if flag == "false":
            if worker != "false":
                raise ValueError("worker_requires_collection_configuration")
            return None
        try:
            if catalog is None:
                raise ValueError
            names = ("max_rows", "max_pages", "max_bytes")
            if any(
                not re.fullmatch(r"[0-9]{1,19}", values.get(name, "")) for name in names
            ):
                raise ValueError
            limits = CollectionLimits(*(int(values[name]) for name in names))
            raw = values.get("allowed_company_ids", "")
            companies = (
                tuple(UUID(item.strip()) for item in raw.split(",")) if raw else ()
            )
            if len(companies) > 1000 or len(set(companies)) != len(companies):
                raise ValueError
            for company, raw_id in zip(
                companies, raw.split(",") if raw else (), strict=True
            ):
                identity(company)
                if str(company) != raw_id.strip():
                    raise ValueError
        except ValueError, KeyError:
            raise ValueError("invalid_collection_configuration") from None
        return cls(limits, companies, worker == "true")


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
    collection: CollectionSettings | None = None
    worker_standby: bool = False

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
        catalog = CatalogSettings.from_mapping(values)
        collection = CollectionSettings.from_mapping(values, catalog)
        standby = values.get("worker_standby", "false")
        if standby not in ("false", "true") or (
            standby == "true" and (catalog is not None or collection is not None)
        ):
            raise ValueError("invalid_worker_standby_configuration")
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
            catalog=catalog,
            collection=collection,
            worker_standby=standby == "true",
        )
