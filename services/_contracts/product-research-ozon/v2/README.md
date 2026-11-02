# product-research-ozon v2 — progress metadata

Read-only GET /internal/v2/companies/{company_id}/runs/{run_id}.
Admission, categories and snapshots retain v1. v1 is unchanged.
Ready month includes origin (api/cache), collected_at (UTC snapshot completion),
used_at (UTC job result acceptance). Other statuses have all three null.
Dates satisfy collected_at <= used_at; metadata is immutable once ready.
Single MVCC progress statement, same revision/frozen periods as v1.
Service auth, company allowlist, source response header, no raw bodies/money/S3 keys.
