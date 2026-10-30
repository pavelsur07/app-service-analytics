#!/usr/bin/env bash
set -euo pipefail
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
exec python3 -B "$ROOT/bin/tests/net_forecast_quality_test.py"
