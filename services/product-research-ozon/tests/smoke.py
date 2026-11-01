"""Container smoke: talks only to the development service."""

import os
import time

import httpx

with httpx.Client(
    base_url="http://product-research-ozon:8080", timeout=10, trust_env=False
) as client:
    deadline = time.monotonic() + 60
    while True:
        try:
            if client.get("/health").status_code == 200:
                break
        except httpx.TransportError:
            pass
        if time.monotonic() >= deadline:
            raise SystemExit("sandbox_api_not_ready")
        time.sleep(1)
    assert client.get("/health").json() == {"status": "ok"}
    ready = client.get("/ready")
    assert ready.status_code == 200, ready.text
    assert ready.json()["collection_enabled"] is False
    path = "/internal/v1/companies/019a3c00-0000-7000-8000-000000000001/categories"
    assert client.get(path).status_code == 401
    response = client.get(
        path,
        headers={"Authorization": "Bearer " + os.environ["RESEARCH_INBOUND_TOKEN"]},
    )
    assert response.status_code == 503
    assert response.json()["error"]["code"] == "not_available"
print("health/readiness/auth/unavailable: OK")
