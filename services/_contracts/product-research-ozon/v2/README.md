# product-research-ozon v2 — progress metadata

Read-only GET /internal/v2/companies/{company_id}/runs/{run_id}.
Admission, categories and snapshots retain v1. v1 is unchanged.
Ready month includes origin (api/cache), collected_at (UTC snapshot completion),
used_at (UTC job result acceptance). Other statuses have all three null.
Known dates satisfy collected_at <= used_at; metadata is immutable once ready.
Single MVCC progress statement, same revision/frozen periods as v1.
Service auth, company allowlist, source response header, no raw bodies/money/S3 keys.

Legacy API-ready jobs этапа 5 не имеют известного used_at: v2 возвращает null,
PHP сохраняет null. Новые API/cache jobs записывают точное время принятия;
origin=cache всегда требует used_at. Неизвестное прошлое время не восстанавливается
из collected_at.
