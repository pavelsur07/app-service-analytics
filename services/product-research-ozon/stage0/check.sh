#!/bin/sh
set -eu

repo=$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)
service="$repo/services/product-research-ozon"
image=$(cat "$service/stage0/python-image.txt")
mkdir -p "$repo/var/product-research-ozon-stage0/stack"
checks=$(mktemp -d "$repo/var/product-research-ozon-stage0/stack/check.XXXXXX")

docker run --rm --platform linux/amd64 -v "$service:/service:ro" -v "$checks:/work" "$image" sh -eu -c '
    python -m pip install --disable-pip-version-check --only-binary=:all: \
        --require-hashes --target /work/site -r /service/stage0/requirements.lock > /work/install.log 2>&1
    export PYTHONPATH=/work/site
    python -m pip check
    python -c "import sys,uuid,fastapi,uvicorn,pydantic,httpx,psycopg,boto3,alembic,sqlalchemy,pytest,jsonschema; print(sys.version); assert uuid.uuid7().version == 7; assert psycopg.pq.__impl__ == \"binary\""
    python -m pip_audit --path /work/site --format json --output /work/audit.json
'

docker run --rm --platform linux/amd64 --network none -e PYTHONPATH=/work/site -e PYTHONDONTWRITEBYTECODE=1 \
    -v "$service:/service:ro" -v "$checks:/work:ro" "$image" sh -eu -c '
    python -m ruff check --no-cache /service/tests/test_stage0_fixtures.py
    python -m ruff format --check --no-cache /service/tests/test_stage0_fixtures.py
    python -m mypy --strict --cache-dir=/tmp/mypy /service/tests/test_stage0_fixtures.py
    python -m unittest discover -s /service/tests -p test_stage0_fixtures.py -v
'
printf 'Stage 0 evidence: %s\n' "$checks"
