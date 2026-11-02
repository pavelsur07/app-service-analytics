"""Explicit scoped diagnostics; never calls MPStats, changes jobs or deletes raw."""

import argparse
import json
from dataclasses import asdict
from datetime import UTC, datetime
from uuid import UUID

import psycopg
from botocore.exceptions import BotoCoreError, ClientError

from product_research_ozon.bootstrap import configured_settings
from product_research_ozon.domain.month import CollectionFailure, identity
from product_research_ozon.infrastructure.diagnostics import JobDiagnostics
from product_research_ozon.infrastructure.snapshot_audit import SnapshotAuditor


def identifier(raw: str) -> UUID:
    try:
        value = UUID(raw)
        identity(value)
        if str(value) != raw:
            raise ValueError
        return value
    except ValueError:
        raise argparse.ArgumentTypeError("invalid_identity") from None


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("operation", choices=("diagnostics", "verify-snapshot"))
    parser.add_argument("company_id", type=identifier)
    parser.add_argument("source_id", type=identifier)
    parser.add_argument("--snapshot-id", type=identifier)
    parser.add_argument("--limit", type=int, default=200)
    args = parser.parse_args()
    try:
        settings = configured_settings()
        if args.operation == "diagnostics":
            result = asdict(
                JobDiagnostics(settings.database_url).report(
                    args.company_id, args.source_id, datetime.now(UTC), args.limit
                )
            )
        else:
            if args.snapshot_id is None:
                raise ValueError("snapshot_required")
            result = asdict(
                SnapshotAuditor(settings).verify(
                    args.company_id, args.source_id, args.snapshot_id
                )
            )
        print(
            json.dumps(
                {
                    "status": "ok",
                    "operation": args.operation,
                    "company_id": str(args.company_id),
                    "source_id": str(args.source_id),
                    "result": result,
                }
            )
        )
    except (
        ValueError,
        RuntimeError,
        OSError,
        psycopg.Error,
        BotoCoreError,
        ClientError,
        CollectionFailure,
    ) as error:
        # Operator CLI boundary: SDK errors and tracebacks may carry secrets.
        print(
            json.dumps(
                {
                    "status": "error",
                    "code": error.code
                    if isinstance(error, CollectionFailure)
                    else "operation_unavailable",
                }
            )
        )
        raise SystemExit(1) from None


if __name__ == "__main__":
    main()
