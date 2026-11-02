"""Company-scoped closed snapshot coordination and provenance."""

from alembic import op

revision = "0005_month_cache"
down_revision = "0004_research_jobs"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.execute("""ALTER TABLE research_job ADD COLUMN origin varchar(8) NOT NULL DEFAULT 'api' CHECK(origin IN ('api','cache')),
        ADD COLUMN used_at timestamptz""")
    op.execute("""UPDATE research_job j SET used_at=s.completed_at FROM month_snapshot s
        WHERE j.company_id=s.company_id AND j.source_id=s.source_id AND j.snapshot_id=s.snapshot_id AND j.status='ready'""")
    op.execute("""CREATE INDEX ix_month_snapshot_legacy_cache ON month_snapshot(company_id,source_id,date_from,date_to)
        WHERE status='ready' AND NOT partial""")
    op.execute("""CREATE TABLE month_cache_slot (
        company_id uuid NOT NULL, source_id uuid NOT NULL, key_hash char(64) NOT NULL,
        key_payload jsonb NOT NULL, owner_job_id uuid NOT NULL,
        PRIMARY KEY(company_id,source_id,key_hash),
        FOREIGN KEY(company_id,source_id,owner_job_id) REFERENCES research_job(company_id,source_id,job_id))""")


def downgrade() -> None:
    # Export provenance/cache journal before production rollback; snapshots and S3 survive.
    op.drop_table("month_cache_slot")
    op.execute("DROP INDEX IF EXISTS ix_month_snapshot_legacy_cache")
    op.drop_column("research_job", "used_at")
    op.drop_column("research_job", "origin")
