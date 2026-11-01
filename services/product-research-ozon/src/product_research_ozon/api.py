import asyncio
import logging
import secrets
import time
from collections.abc import Callable
from datetime import UTC, date, datetime
from math import ceil
from typing import Annotated
from uuid import UUID, uuid7

from fastapi import FastAPI, Query, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from pydantic import BaseModel, ConfigDict, Field, field_validator
from starlette.exceptions import HTTPException
from starlette.types import ASGIApp, Message, Receive, Scope, Send

from product_research_ozon.catalogs.service import CategoryCatalog
from product_research_ozon.domain.catalog import CatalogUnavailable
from product_research_ozon.infrastructure.config import Settings
from product_research_ozon.infrastructure.observability import Event, emit

UUID_PATTERN = r"^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$"
Identifier = Annotated[str, Field(pattern=UUID_PATTERN)]


class Month(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True)
    month: Annotated[str, Field(pattern=r"^20[0-9]{2}-(0[1-9]|1[0-2])$")]
    date_from: Annotated[str, Field(pattern=r"^20[0-9]{2}-(0[1-9]|1[0-2])-01$")]
    date_to: Annotated[
        str, Field(pattern=r"^20[0-9]{2}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$")
    ]
    days: Annotated[int, Field(ge=1, le=31)]
    partial: bool

    @field_validator("days", mode="before")
    @classmethod
    def integral_json_number(cls, value: object) -> object:
        # JSON Schema integer includes 31.0; strings and booleans stay invalid.
        if isinstance(value, float) and value.is_integer():
            return int(value)
        return value

    @field_validator("date_from", "date_to")
    @classmethod
    def valid_date(cls, value: str) -> str:
        date.fromisoformat(value)
        return value


class RunRequest(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True)
    schema_version: Annotated[str, Field(pattern="^1$")]
    company_id: Identifier
    run_id: Identifier
    project_id: Identifier
    source_connection_id: Identifier
    category_path: Annotated[str, Field(min_length=1, max_length=2048)]
    fbs: Annotated[bool, Field()]
    currency: Annotated[str, Field(pattern="^RUB$")]
    months: Annotated[list[Month], Field(min_length=1, max_length=24)]


def error(code: str, status: int) -> JSONResponse:
    return JSONResponse(
        {"schema_version": "1", "error": {"code": code}},
        status_code=status,
        headers={"Cache-Control": "no-store"},
    )


class Boundary:
    def __init__(self, app: ASGIApp, token: str, logger: logging.Logger) -> None:
        self.app, self.token, self.logger = app, token.encode("ascii"), logger

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return
        start = time.monotonic()
        status = 500
        started = False
        failure_class: str | None = None
        trace_id = str(uuid7())
        scope["research_trace_id"] = trace_id
        path = scope["path"]
        company_id: str | None = None
        parts = path.split("/")
        if len(parts) > 4 and parts[1:4] == ["internal", "v1", "companies"]:
            try:
                candidate = UUID(parts[4])
                if candidate.version == 7:
                    company_id = str(candidate)
            except ValueError:
                pass

        async def traced_send(message: Message) -> None:
            nonlocal status, started
            if message["type"] == "http.response.start":
                started = True
                status = message["status"]
                message["headers"] = [
                    *message.get("headers", []),
                    (b"x-trace-id", trace_id.encode()),
                ]
            await send(message)

        try:
            if path.startswith("/internal"):
                auth = [
                    value
                    for key, value in scope["headers"]
                    if key.lower() == b"authorization"
                ]
                if len(auth) != 1 or not secrets.compare_digest(
                    auth[0], b"Bearer " + self.token
                ):
                    await error("unauthorized", 401)(scope, receive, traced_send)
                    return
                body = bytearray()
                async with asyncio.timeout(5):
                    while True:
                        message = await receive()
                        if message["type"] == "http.disconnect":
                            return
                        body.extend(message.get("body", b""))
                        if len(body) > 65536:
                            await error("payload_too_large", 413)(
                                scope, receive, traced_send
                            )
                            return
                        if not message.get("more_body", False):
                            break
                consumed = False

                async def replay() -> Message:
                    nonlocal consumed
                    if not consumed:
                        consumed = True
                        return {
                            "type": "http.request",
                            "body": bytes(body),
                            "more_body": False,
                        }
                    return await receive()

                await self.app(scope, replay, traced_send)
            else:
                await self.app(scope, receive, traced_send)
        except TimeoutError:
            if not started:
                await error("request_timeout", 408)(scope, receive, traced_send)
        except Exception as failure:  # noqa: BLE001 — HTTP trust boundary: traceback may expose credentials; emit only class.
            failure_class = type(failure).__name__
            if not started:
                await error("internal_error", 500)(scope, receive, traced_send)
        finally:
            level = (
                logging.WARNING
                if status == 503
                else logging.ERROR
                if status >= 500
                else logging.INFO
            )
            emit(
                self.logger,
                Event(
                    company_id=company_id,
                    trace_id=trace_id,
                    resource_type="internal_api"
                    if path.startswith("/internal")
                    else "health",
                    outcome="ok"
                    if status < 400
                    else "not_ready"
                    if status == 503
                    else "error",
                    error_class=failure_class
                    or (None if status < 400 else f"http_{status}"),
                    duration_ms=int((time.monotonic() - start) * 1000),
                ),
                level,
            )


def create_app(
    settings: Settings,
    readiness: Callable[[], bool],
    logger: logging.Logger | None = None,
    catalogs: CategoryCatalog | None = None,
) -> FastAPI:
    app = FastAPI(
        docs_url=None, redoc_url=None, openapi_url=None, redirect_slashes=False
    )
    app.add_middleware(
        Boundary,
        token=settings.inbound_token,
        logger=logger or logging.getLogger("product-research-ozon"),
    )

    @app.exception_handler(HTTPException)
    async def http_error(request: Request, exc: HTTPException) -> JSONResponse:
        return error(
            "not_found"
            if exc.status_code == 404
            else "method_not_allowed"
            if exc.status_code == 405
            else "contract_violation",
            exc.status_code,
        )

    @app.exception_handler(RequestValidationError)
    async def validation_error(
        request: Request, exc: RequestValidationError
    ) -> JSONResponse:
        return error("contract_violation", 422)

    @app.get("/health")
    def health() -> dict[str, str]:
        return {"status": "ok"}

    @app.get("/ready")
    def ready() -> JSONResponse:
        if not readiness():
            return error("not_ready", 503)
        return JSONResponse({"status": "ready", "collection_enabled": False})

    prefix = "/internal/v1/companies/{company_id}"

    @app.get(prefix + "/categories")
    def categories(
        company_id: Identifier,
        request: Request,
        limit: Annotated[int, Query(ge=1, le=100)] = 100,
        cursor: Annotated[
            str | None, Query(max_length=256, pattern=r"^[a-zA-Z0-9_-]+$")
        ] = None,
    ) -> JSONResponse:
        params = request.query_params.multi_items()
        if len({key for key, _ in params}) != len(params) or any(
            key not in ("limit", "cursor") for key, _ in params
        ):
            return error("contract_violation", 422)
        if catalogs is None:
            return error("not_available", 503)
        try:
            return JSONResponse(
                catalogs.page(
                    UUID(company_id),
                    limit,
                    cursor,
                    request.scope.get("research_trace_id"),
                ),
                headers={
                    "Cache-Control": "no-store",
                    "X-Research-Source-Id": str(catalogs.source_id),
                },
            )
        except CatalogUnavailable as failure:
            code = (
                failure.code
                if failure.code in ("conflict", "contract_violation")
                else "not_available"
            )
            response = error(
                code,
                409
                if code == "conflict"
                else 422
                if code == "contract_violation"
                else 503,
            )
            if failure.retry_at is not None:
                response.headers["Retry-After"] = str(
                    max(0, ceil((failure.retry_at - datetime.now(UTC)).total_seconds()))
                )
            return response

    @app.post(prefix + "/runs")
    def submit(company_id: Identifier, body: RunRequest) -> JSONResponse:
        if company_id != body.company_id or not body.fbs:
            return error("contract_violation", 422)
        return error("not_available", 503)

    @app.get(prefix + "/runs/{run_id}")
    def progress(company_id: Identifier, run_id: Identifier) -> JSONResponse:
        return error("not_available", 503)

    @app.get(prefix + "/snapshots/{snapshot_id}")
    def snapshot(company_id: Identifier, snapshot_id: Identifier) -> JSONResponse:
        return error("not_available", 503)

    return app
