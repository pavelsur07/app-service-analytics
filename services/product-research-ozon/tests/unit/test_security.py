import json
import logging
from io import StringIO

import pytest
from fastapi.testclient import TestClient

from product_research_ozon.api import create_app
from product_research_ozon.infrastructure.config import Settings
from product_research_ozon.infrastructure.observability import SafeJsonFormatter

INBOUND = "php-to-python-" + "a" * 32
OUTBOUND = "python-to-php-" + "b" * 32
COMPANY = "019a3c00-0000-7000-8000-000000000001"


def settings(**changes: str) -> Settings:
    values = {
        "database_url": "postgresql://test:secret@postgres/product_research_ozon_test",
        "inbound_token": INBOUND,
        "outbound_token": OUTBOUND,
        "s3_endpoint": "http://s3:8333",
        "s3_bucket": "conwix-test",
        "s3_access_key": "research-dev",
        "s3_secret_key": "research-secret",
        "worker_enabled": "false",
    }
    values.update(changes)
    return Settings.from_mapping(values)


@pytest.mark.parametrize("token", ["", "Bearer wrong", "Bearer " + OUTBOUND])
def test_unauthorized_before_invalid_body(token: str) -> None:
    with TestClient(create_app(settings(), lambda: True)) as client:
        response = client.post(
            "/internal/v1/companies/bad/runs",
            content="not-json",
            headers={"Authorization": token},
        )
    assert response.status_code == 401
    assert response.json()["error"]["code"] == "unauthorized"
    assert "not-json" not in response.text


def test_valid_request_is_explicitly_unavailable() -> None:
    with TestClient(create_app(settings(), lambda: True)) as client:
        response = client.get(
            f"/internal/v1/companies/{COMPANY}/categories",
            params={"limit": 10},
            headers={"Authorization": "Bearer " + INBOUND},
        )
    assert response.status_code == 503
    assert response.json()["error"]["code"] == "not_available"


@pytest.mark.parametrize("ready", [True, False])
def test_health_is_distinct_from_readiness(ready: bool) -> None:
    with TestClient(create_app(settings(), lambda: ready)) as client:
        assert client.get("/health").status_code == 200
        assert client.get("/ready").status_code == (200 if ready else 503)
        assert client.get("/docs").status_code == 404


@pytest.mark.parametrize(
    "changes",
    [
        {"inbound_token": "short"},
        {"outbound_token": INBOUND},
        {"worker_enabled": "true"},
    ],
)
def test_fail_closed_configuration(changes: dict[str, str]) -> None:
    with pytest.raises(ValueError) as error:
        settings(**changes)
    assert INBOUND not in str(error.value)
    assert "secret" not in str(error.value)


def test_duplicate_auth_and_oversized_body() -> None:
    with TestClient(create_app(settings(), lambda: True)) as client:
        response = client.get(
            f"/internal/v1/companies/{COMPANY}/categories",
            headers=[("Authorization", "Bearer " + INBOUND)] * 2,
        )
        assert response.status_code == 401
        response = client.post(
            f"/internal/v1/companies/{COMPANY}/runs",
            content="x" * 65537,
            headers={"Authorization": "Bearer " + INBOUND},
        )
        assert response.status_code == 413


def test_log_excludes_untrusted_input() -> None:
    stream = StringIO()
    logger = logging.getLogger("research-test")
    logger.handlers = [logging.StreamHandler(stream)]
    logger.handlers[0].setFormatter(SafeJsonFormatter())
    logger.setLevel(logging.INFO)
    with TestClient(create_app(settings(), lambda: True, logger)) as client:
        client.get(
            f"/internal/v1/companies/{COMPANY}/categories?token=TOP_SECRET",
            headers={"Authorization": "Bearer " + INBOUND, "X-Trace-Id": "TOP_SECRET"},
        )
    output = stream.getvalue()
    assert INBOUND not in output and "TOP_SECRET" not in output
    record = json.loads(output)
    assert record["company_id"] == COMPANY
    assert record["service"] == "product-research-ozon"
    assert record["trace_id"] != "TOP_SECRET"


def test_category_query_rejects_unknown_and_duplicate_fields() -> None:
    with TestClient(create_app(settings(), lambda: True)) as client:
        for query in (
            "limit=10&token=secret",
            "limit=1&limit=2",
            "limit=0",
            "cursor=http://evil",
        ):
            response = client.get(
                f"/internal/v1/companies/{COMPANY}/categories?{query}",
                headers={"Authorization": "Bearer " + INBOUND},
            )
            assert response.status_code == 422


def test_unexpected_exception_is_sanitized() -> None:
    def failed() -> bool:
        raise RuntimeError("secret-connection-string")

    stream = StringIO()
    logger = logging.getLogger("research-error-test")
    logger.handlers = [logging.StreamHandler(stream)]
    logger.handlers[0].setFormatter(SafeJsonFormatter())
    logger.setLevel(logging.INFO)
    with TestClient(create_app(settings(), failed, logger)) as client:
        response = client.get("/ready")
    assert response.status_code == 500
    assert response.json()["error"]["code"] == "internal_error"
    assert "secret-connection-string" not in response.text + stream.getvalue()


def test_run_examples_match_http_validation() -> None:
    from pathlib import Path

    cases = json.loads(
        Path("/contracts/product-research-ozon/v1/examples.json").read_text()
    )
    with TestClient(create_app(settings(), lambda: True)) as client:
        for case in cases:
            if case["definition"] == "run_request":
                response = client.post(
                    f"/internal/v1/companies/{COMPANY}/runs",
                    json=case["payload"],
                    headers={"Authorization": "Bearer " + INBOUND},
                )
                assert response.status_code == (503 if case["valid"] else 422), case[
                    "name"
                ]
        response = client.post(
            "/internal/v1/companies/019a3c00-0000-7000-8000-000000000099/runs",
            json=cases[0]["payload"],
            headers={"Authorization": "Bearer " + INBOUND},
        )
        assert response.status_code == 422


def test_dependency_traceback_is_not_formatted() -> None:
    formatter = SafeJsonFormatter()
    record = logging.LogRecord(
        "sdk",
        logging.ERROR,
        "",
        0,
        "secret-body",
        (),
        (RuntimeError, RuntimeError("secret-dsn"), None),
    )
    output = formatter.format(record)
    assert "secret" not in output
    assert json.loads(output)["error_class"] == "RuntimeError"


def test_errors_use_contract_and_request_has_deadline() -> None:
    import asyncio

    from starlette.types import Message, Receive, Scope, Send

    from product_research_ozon.api import Boundary

    async def exercise() -> None:
        messages: list[Message] = []

        async def downstream(scope: Scope, receive: Receive, send: Send) -> None:
            raise AssertionError("slow body must not reach route")

        async def slow_body() -> Message:
            await asyncio.sleep(6)
            return {"type": "http.request", "body": b"", "more_body": False}

        async def send(message: Message) -> None:
            messages.append(message)

        boundary = Boundary(downstream, INBOUND, logging.getLogger("deadline"))
        await boundary(
            {
                "type": "http",
                "path": f"/internal/v1/companies/{COMPANY}/runs",
                "headers": [(b"authorization", ("Bearer " + INBOUND).encode())],
            },
            slow_body,
            send,
        )
        assert messages[0]["status"] == 408
        assert json.loads(messages[1]["body"])["error"]["code"] == "request_timeout"

    asyncio.run(exercise())
    with TestClient(create_app(settings(), lambda: True)) as client:
        headers = {"Authorization": "Bearer " + INBOUND}
        assert (
            client.get("/internal/missing", headers=headers).json()["error"]["code"]
            == "not_found"
        )
        assert (
            client.delete(
                f"/internal/v1/companies/{COMPANY}/categories", headers=headers
            ).json()["error"]["code"]
            == "method_not_allowed"
        )
