"""Wait only for the sandbox S3 process, not for production dependencies."""

import time

import httpx

with httpx.Client(timeout=2, trust_env=False) as client:
    deadline = time.monotonic() + 60
    while True:
        try:
            if client.head("http://s3:8333/").status_code < 500:
                break
        except httpx.TransportError:
            pass
        if time.monotonic() >= deadline:
            raise SystemExit("sandbox_storage_not_ready")
        time.sleep(1)
