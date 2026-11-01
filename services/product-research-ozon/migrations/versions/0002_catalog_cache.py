"""Company/source-scoped disposable category metadata cache, ADR-050."""

from alembic import op

revision = "0002_catalog_cache"
down_revision = "0001_baseline"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.execute("""CREATE TABLE catalog_state (
        company_id uuid NOT NULL, source_id uuid NOT NULL, revision bigint NOT NULL DEFAULT 0,
        generated_at timestamptz, lease_id uuid, lease_until timestamptz,
        next_attempt_at timestamptz, error_code varchar(32),
        PRIMARY KEY (company_id, source_id), CHECK (revision >= 0),
        CHECK ((revision = 0 AND generated_at IS NULL) OR (revision > 0 AND generated_at IS NOT NULL)))""")
    op.execute("""CREATE TABLE catalog_entry (
        company_id uuid NOT NULL, source_id uuid NOT NULL, path_key char(64) NOT NULL,
        path varchar(2048) NOT NULL, provider_id varchar(128),
        PRIMARY KEY (company_id, source_id, path_key),
        FOREIGN KEY (company_id, source_id) REFERENCES catalog_state (company_id, source_id))""")
    op.execute("""CREATE TABLE catalog_provider_gate (
        source_id uuid PRIMARY KEY, lease_id uuid, lease_until timestamptz,
        next_request_at timestamptz NOT NULL, active_generation bigint NOT NULL CHECK (active_generation > 0), paused_generation bigint)""")


def downgrade() -> None:
    # Derived metadata only, no projects/snapshots or irreplaceable human data.
    op.drop_table("catalog_entry")
    op.drop_table("catalog_state")
    op.drop_table("catalog_provider_gate")
