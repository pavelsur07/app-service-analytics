import os

from alembic import context
from sqlalchemy import create_engine, pool

url = os.environ["RESEARCH_DATABASE_URL"].replace(
    "postgresql://", "postgresql+psycopg://", 1
)
# No engine echo or logging_config: DSN and parameters must not reach logs.
engine = create_engine(
    url, poolclass=pool.NullPool, connect_args={"connect_timeout": 2}
)
with engine.connect() as connection:
    context.configure(connection=connection, target_metadata=None)
    with context.begin_transaction():
        context.run_migrations()
