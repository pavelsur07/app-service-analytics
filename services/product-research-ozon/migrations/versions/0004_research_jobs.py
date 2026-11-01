"""Durable run admission, monthly jobs and fair dispatch (ADR-050)."""

from alembic import op

revision = "0004_research_jobs"
down_revision = "0003_month_snapshots"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.execute("""CREATE TABLE research_run (
        company_id uuid NOT NULL, run_id uuid NOT NULL, source_id uuid NOT NULL, project_id uuid NOT NULL,
        request_hash char(64) NOT NULL, request_payload jsonb NOT NULL,
        created_at timestamptz NOT NULL, revision bigint NOT NULL DEFAULT 1 CHECK(revision>0),
        status varchar(16) NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','ready','error')),
        PRIMARY KEY(company_id,run_id), UNIQUE(company_id,source_id,run_id))""")
    op.execute("""CREATE TABLE research_job (
        company_id uuid NOT NULL, source_id uuid NOT NULL, run_id uuid NOT NULL, job_id uuid NOT NULL,
        month varchar(7) NOT NULL, snapshot_id uuid NOT NULL,
        status varchar(16) NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','ready','error')),
        deadline timestamptz NOT NULL, next_attempt_at timestamptz NOT NULL, last_claimed_at timestamptz NOT NULL,
        lease_id uuid, lease_until timestamptz, network_retries integer NOT NULL DEFAULT 0 CHECK(network_retries BETWEEN 0 AND 3),
        retry_cursor integer NOT NULL DEFAULT 0 CHECK(retry_cursor>=0),
        completeness_retries integer NOT NULL DEFAULT 0 CHECK(completeness_retries BETWEEN 0 AND 1),
        attempt_no integer NOT NULL DEFAULT 1 CHECK(attempt_no>0), row_count integer CHECK(row_count>=0), error_code varchar(32),
        PRIMARY KEY(company_id,job_id), UNIQUE(company_id,source_id,job_id), UNIQUE(company_id,run_id,month),
        FOREIGN KEY(company_id,source_id,run_id) REFERENCES research_run(company_id,source_id,run_id),
        FOREIGN KEY(company_id,source_id,snapshot_id) REFERENCES month_snapshot(company_id,source_id,snapshot_id) DEFERRABLE INITIALLY DEFERRED,
        CHECK((lease_id IS NULL)=(lease_until IS NULL)),
        CHECK((status='error')=(error_code IS NOT NULL)))""")
    op.execute("""ALTER TABLE month_snapshot ADD COLUMN job_id uuid,
        ADD CONSTRAINT fk_snapshot_job FOREIGN KEY(company_id,source_id,job_id)
        REFERENCES research_job(company_id,source_id,job_id) DEFERRABLE INITIALLY DEFERRED""")
    op.execute(
        "CREATE INDEX idx_research_job_due ON research_job(source_id,next_attempt_at,company_id) WHERE status IN ('queued','running')"
    )
    op.execute("""CREATE TABLE research_dispatch_turn (
        company_id uuid NOT NULL, source_id uuid NOT NULL, last_served_at timestamptz NOT NULL DEFAULT '-infinity',
        PRIMARY KEY(company_id,source_id))""")


def downgrade() -> None:
    # Export run/job journals before production down; raw S3 objects and old snapshots stay.
    op.execute("ALTER TABLE month_snapshot DROP CONSTRAINT fk_snapshot_job")
    op.drop_table("research_dispatch_turn")
    op.drop_table("research_job")
    op.drop_table("research_run")
    op.drop_column("month_snapshot", "job_id")
