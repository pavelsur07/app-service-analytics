import psycopg
from botocore.exceptions import BotoCoreError, ClientError

from product_research_ozon.infrastructure.config import Settings
from product_research_ozon.infrastructure.storage import RawStorage

REVISION = "0001_baseline"


class Readiness:
    def __init__(self, settings: Settings, storage: RawStorage) -> None:
        self._settings = settings
        self._storage = storage

    def __call__(self) -> bool:
        try:
            with psycopg.connect(
                self._settings.database_url,
                connect_timeout=2,
                options="-c statement_timeout=2000",
            ) as connection:
                row = connection.execute(
                    "SELECT version_num FROM alembic_version LIMIT 2"
                ).fetchall()
                if row != [(REVISION,)]:
                    return False
            self._storage.ready()
        except psycopg.Error, BotoCoreError, ClientError, ValueError:
            # Details can contain connection strings, headers or SDK response bodies.
            return False
        return True
