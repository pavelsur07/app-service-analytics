"""Technical monthly raw snapshots; no financial facts (ADR-050)."""

from alembic import op

revision = "0003_month_snapshots"
down_revision = "0002_catalog_cache"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.execute("""CREATE TABLE month_snapshot (
        company_id uuid NOT NULL, source_id uuid NOT NULL, snapshot_id uuid NOT NULL,
        category_path varchar(2048) NOT NULL, date_from date NOT NULL, date_to date NOT NULL,
        partial boolean NOT NULL, fbs boolean NOT NULL DEFAULT TRUE CHECK(fbs), currency char(3) NOT NULL DEFAULT 'RUB' CHECK(currency='RUB'), profile_version smallint NOT NULL DEFAULT 1 CHECK(profile_version=1), generation bigint NOT NULL CHECK (generation>0),
        max_rows integer NOT NULL CHECK (max_rows>0), max_pages integer NOT NULL CHECK (max_pages>0), max_bytes bigint NOT NULL CHECK (max_bytes>0),
        status varchar(16) NOT NULL DEFAULT 'collecting' CHECK (status IN ('collecting','ready','error')),
        created_at timestamptz NOT NULL, completed_at timestamptz, total integer CHECK (total>=0),
        cursor_row integer NOT NULL DEFAULT 0 CHECK (cursor_row>=0), pages integer NOT NULL DEFAULT 0 CHECK (pages>=0),
        byte_size bigint NOT NULL DEFAULT 0 CHECK (byte_size>=0),
        lease_id uuid, lease_until timestamptz, next_attempt_at timestamptz, error_code varchar(32),
        manifest_sha256 char(64), manifest_size integer,
        PRIMARY KEY(company_id,source_id,snapshot_id),
        CHECK (date_from<=date_to AND EXTRACT(DAY FROM date_from)=1 AND date_trunc('month',date_from)=date_trunc('month',date_to)),
        CHECK ((lease_id IS NULL)=(lease_until IS NULL)),
        CHECK (total IS NULL OR cursor_row<=total),
        CHECK (cursor_row<=max_rows AND pages<=max_pages AND byte_size<=max_bytes),
        CHECK ((status='ready' AND total IS NOT NULL AND completed_at IS NOT NULL AND manifest_sha256 IS NOT NULL AND manifest_size>0 AND total=cursor_row AND pages>0)
            OR (status<>'ready' AND completed_at IS NULL AND manifest_sha256 IS NULL AND manifest_size IS NULL)))""")
    op.execute("""CREATE TABLE snapshot_page (
        company_id uuid NOT NULL, source_id uuid NOT NULL, snapshot_id uuid NOT NULL,
        page_no integer NOT NULL CHECK (page_no>=0), start_row integer NOT NULL CHECK (start_row>=0),
        end_row integer NOT NULL, total integer NOT NULL CHECK (total>=0),
        sha256 char(64) NOT NULL, byte_size integer NOT NULL CHECK (byte_size>0),
        PRIMARY KEY(company_id,source_id,snapshot_id,page_no),
        FOREIGN KEY(company_id,source_id,snapshot_id) REFERENCES month_snapshot(company_id,source_id,snapshot_id),
        CHECK (end_row>=start_row AND end_row<=total))""")
    op.execute("""CREATE TABLE snapshot_sku_check (
        company_id uuid NOT NULL, source_id uuid NOT NULL, snapshot_id uuid NOT NULL,
        sku bigint NOT NULL CHECK (sku>0),
        PRIMARY KEY(company_id,source_id,snapshot_id,sku),
        FOREIGN KEY(company_id,source_id,snapshot_id) REFERENCES month_snapshot(company_id,source_id,snapshot_id))""")


def downgrade() -> None:
    # Only technical state; immutable S3 objects and existing catalog remain untouched.
    # In production preserve/export this journal before down to retain raw discoverability.
    op.drop_table("snapshot_sku_check")
    op.drop_table("snapshot_page")
    op.drop_table("month_snapshot")
