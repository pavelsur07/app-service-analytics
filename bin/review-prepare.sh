#!/bin/sh
# Стабильная точка входа Makefile; Git-снимок и метаданные — в review.py.
set -eu
cd "$(dirname "$0")/.."
exec python3 -X utf8 bin/review.py prepare
