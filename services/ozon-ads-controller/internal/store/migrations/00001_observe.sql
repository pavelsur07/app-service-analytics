-- +goose Up
CREATE TABLE ads_account (
 company_id uuid NOT NULL, account_id uuid NOT NULL,
 next_poll timestamptz NOT NULL, next_tick timestamptz NOT NULL,
 last_poll_at timestamptz, pause_until timestamptz,
 lease_token uuid, lease_until timestamptz,
 PRIMARY KEY (company_id, account_id)
);
CREATE INDEX ads_account_due ON ads_account(next_tick, lease_until);
CREATE TABLE ads_policy (
 company_id uuid NOT NULL, account_id uuid NOT NULL, policy_id uuid NOT NULL,
 campaign_id text NOT NULL, version bigint NOT NULL CHECK (version > 0),
 document jsonb NOT NULL, runtime jsonb NOT NULL DEFAULT '{}', published_at timestamptz,
 daily_limit_minor bigint GENERATED ALWAYS AS ((document->'daily_limit'->>'amount_minor')::bigint) STORED,
 currency char(3) GENERATED ALWAYS AS ((document->'daily_limit'->>'currency')) STORED,
 effective_minor bigint GENERATED ALWAYS AS ((runtime->'effective'->>'amount_minor')::bigint) STORED,
 PRIMARY KEY(company_id, account_id, policy_id),
 UNIQUE(company_id, account_id, campaign_id),
 FOREIGN KEY(company_id, account_id) REFERENCES ads_account(company_id, account_id)
);
CREATE TABLE ads_auth_rejection (
 company_id uuid NOT NULL, account_id uuid NOT NULL, credential_revision timestamptz NOT NULL,
 PRIMARY KEY(company_id,account_id)
);
-- Sliding 24h HTTP quota reservations. No vendor report generations occur here.
CREATE TABLE ads_http_quota (
 scope text NOT NULL, window_start timestamptz NOT NULL, used integer NOT NULL CHECK(used>0),
 PRIMARY KEY(scope, window_start)
);
CREATE INDEX ads_quota_expiry ON ads_http_quota(window_start);
-- +goose Down
DROP TABLE ads_http_quota;
DROP TABLE ads_auth_rejection;
DROP TABLE ads_policy;
DROP TABLE ads_account;
