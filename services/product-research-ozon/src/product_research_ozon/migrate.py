import os
import sys

import psycopg
from alembic import command
from alembic.config import Config
from alembic.util.exc import CommandError
from sqlalchemy.exc import SQLAlchemyError

from product_research_ozon.infrastructure.readiness import REVISION


def main() -> int:
    try:
        action = sys.argv[1]
        if action == "check":
            with psycopg.connect(
                os.environ["RESEARCH_DATABASE_URL"],
                connect_timeout=2,
                options="-c statement_timeout=2000",
            ) as connection:
                if connection.execute(
                    "SELECT version_num FROM alembic_version LIMIT 2"
                ).fetchall() != [(REVISION,)]:
                    raise ValueError
        elif action in ("up", "down"):
            config = Config("/service/alembic.ini")
            if action == "up":
                command.upgrade(config, "head")
            else:
                command.downgrade(config, "base")
        else:
            raise ValueError
    except (
        psycopg.Error,
        SQLAlchemyError,
        CommandError,
        ValueError,
        KeyError,
        IndexError,
    ):
        print("migration_check_or_command_failed", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
