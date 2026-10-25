package store

import (
	"context"
	"fmt"
	"sort"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
)

// MaxMissingListed — сколько пропущенных периодов перечислять на ресурс.
const MaxMissingListed = 31

// StatusReport — состояние загрузки для /internal/fetch-status. Без сумм,
// ключей и тела ответов.
type StatusReport struct {
	Cabinets         []CabinetStatus `json:"cabinets"`
	StoppedResources []string        `json:"stopped_resources"`
	// NextCursor — marketplace_account_id последнего кабинета страницы;
	// пусто — страница последняя.
	NextCursor string `json:"next_cursor"`
}

// StatusPage — страница кабинетов отчёта: keyset по marketplace_account_id
// (services/AGENTS.md §5 — любой список с лимитом).
type StatusPage struct {
	Cursor string // пусто — с начала
	Limit  int
}

type CabinetStatus struct {
	CompanyID      string     `json:"company_id"`
	AccountID      string     `json:"marketplace_account_id"`
	SuspendedUntil *time.Time `json:"suspended_until"`
	SuspendReason  string     `json:"suspend_reason,omitempty"`
	AuthFailures   int        `json:"auth_failures"`
	// AuthPausedKeyVersion — версия ключей продавца, отказанная Ozon
	// AuthPauseFailures раз подряд: кабинет стоит без срока, пока PHP
	// не выдаст другую (ADR-043 п. 4). Ads* — то же для ключа рекламы.
	AuthPausedKeyVersion    *time.Time       `json:"auth_paused_key_version"`
	AdsSuspendedUntil       *time.Time       `json:"ads_suspended_until"`
	AdsAuthFailures         int              `json:"ads_auth_failures"`
	AdsAuthPausedKeyVersion *time.Time       `json:"ads_auth_paused_key_version"`
	Resources               []ResourceStatus `json:"resources"`
	Limits                  []Limit          `json:"limits"`
	Outcomes24h             map[string]int   `json:"outcomes_24h"`
}

type ResourceStatus struct {
	ResourceType   string         `json:"resource_type"`
	ByStatus       map[string]int `json:"by_status"`
	LastSuccessAt  *time.Time     `json:"last_success_at"`
	Revised        int            `json:"revised"` // задачи, у которых источник менялся (revision > 1)
	MissingPeriods []string       `json:"missing_periods"`
	MissingCount   int            `json:"missing_count"`
}

// StatusAllConnections собирает отчёт по странице кабинетов. Кабинет —
// подключение, у которого есть задачи окна, состояние или лимиты. Статусы
// задач и пропуски — по периодам окна (>= windowFrom) и справочникам,
// исходы — счётчиками за сутки, не по журналу попыток (services/AGENTS.md
// §5). Межарендаторное чтение операционной задачи (CLAUDE.md §1, ADR-043):
// состояние загрузки сервиса по подключениям всех компаний для
// /internal/fetch-status.
func (t *Tasks) StatusAllConnections(ctx context.Context, now time.Time, windowFrom string, page StatusPage) (StatusReport, error) {
	refs, next, err := t.statusCabinets(ctx, windowFrom, page)
	if err != nil {
		return StatusReport{}, err
	}
	report := StatusReport{Cabinets: []CabinetStatus{}, StoppedResources: []string{}, NextCursor: next}
	if err := t.stoppedResources(ctx, &report); err != nil {
		return StatusReport{}, err
	}
	if len(refs) == 0 {
		return report, nil
	}
	companies, accounts := splitRefs(refs)

	type resourceKey struct {
		ref      account.Ref
		resource string
	}
	cabinets := make(map[account.Ref]*CabinetStatus, len(refs))
	for _, ref := range refs {
		cabinets[ref] = &CabinetStatus{CompanyID: ref.CompanyID, AccountID: ref.AccountID, Outcomes24h: map[string]int{}, Resources: []ResourceStatus{}, Limits: []Limit{}}
	}
	resources := map[resourceKey]*ResourceStatus{}
	res := func(ref account.Ref, resource string) *ResourceStatus {
		k := resourceKey{ref, resource}
		if r, ok := resources[k]; ok {
			return r
		}
		r := &ResourceStatus{ResourceType: resource, ByStatus: map[string]int{}, MissingPeriods: []string{}}
		resources[k] = r
		return r
	}

	rows, err := t.pool.Query(ctx, `
		SELECT company_id, marketplace_account_id, resource_type, status, count(*), max(last_success_at),
		       count(*) FILTER (WHERE revision > 1)
		FROM fetch_task
		WHERE (company_id, marketplace_account_id) IN (SELECT * FROM unnest($1::uuid[], $2::uuid[]))
		  AND `+inWindow("$3")+`
		GROUP BY 1, 2, 3, 4`, companies, accounts, windowFrom)
	if err != nil {
		return StatusReport{}, fmt.Errorf("store: status tasks: %w", err)
	}
	for rows.Next() {
		var ref account.Ref
		var resource, status string
		var n, revised int
		var last *time.Time
		if err := rows.Scan(&ref.CompanyID, &ref.AccountID, &resource, &status, &n, &last, &revised); err != nil {
			rows.Close()
			return StatusReport{}, fmt.Errorf("store: status tasks scan: %w", err)
		}
		r := res(ref, resource)
		r.ByStatus[status] = n
		r.Revised += revised
		if last != nil && (r.LastSuccessAt == nil || last.After(*r.LastSuccessAt)) {
			r.LastSuccessAt = last
		}
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return StatusReport{}, fmt.Errorf("store: status tasks: %w", err)
	}

	// Не больше MaxMissingListed периодов на ресурс; общее число — оконной
	// функцией, а не всеми строками.
	rows, err = t.pool.Query(ctx, `
		SELECT company_id, marketplace_account_id, resource_type, period_key, missing FROM (
			SELECT company_id, marketplace_account_id, resource_type, period_key,
			       count(*) OVER w AS missing, row_number() OVER (w ORDER BY period_key) AS n
			FROM fetch_task
			WHERE (company_id, marketplace_account_id) IN (SELECT * FROM unnest($1::uuid[], $2::uuid[]))
			  AND status <> 'done' AND period_key <> 'ref' AND `+inWindow("$3")+`
			WINDOW w AS (PARTITION BY company_id, marketplace_account_id, resource_type)
		) m WHERE n <= $4
		ORDER BY company_id, marketplace_account_id, resource_type, period_key`, companies, accounts, windowFrom, MaxMissingListed)
	if err != nil {
		return StatusReport{}, fmt.Errorf("store: status missing: %w", err)
	}
	for rows.Next() {
		var ref account.Ref
		var resource, period string
		var missing int
		if err := rows.Scan(&ref.CompanyID, &ref.AccountID, &resource, &period, &missing); err != nil {
			rows.Close()
			return StatusReport{}, fmt.Errorf("store: status missing scan: %w", err)
		}
		r := res(ref, resource)
		r.MissingCount = missing
		r.MissingPeriods = append(r.MissingPeriods, period)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return StatusReport{}, fmt.Errorf("store: status missing: %w", err)
	}

	rows, err = t.pool.Query(ctx, `
		SELECT company_id, marketplace_account_id, suspended_until, COALESCE(suspend_reason, ''), auth_failures,
		       auth_key_version, ads_suspended_until, ads_auth_failures, ads_auth_key_version
		FROM connection_state
		WHERE (company_id, marketplace_account_id) IN (SELECT * FROM unnest($1::uuid[], $2::uuid[]))`, companies, accounts)
	if err != nil {
		return StatusReport{}, fmt.Errorf("store: status state: %w", err)
	}
	for rows.Next() {
		var ref account.Ref
		var reason string
		var until, version, adsUntil, adsVersion *time.Time
		var failures, adsFailures int
		if err := rows.Scan(&ref.CompanyID, &ref.AccountID, &until, &reason, &failures,
			&version, &adsUntil, &adsFailures, &adsVersion); err != nil {
			rows.Close()
			return StatusReport{}, fmt.Errorf("store: status state scan: %w", err)
		}
		c := cabinets[ref]
		if until != nil && until.After(now) {
			c.SuspendedUntil, c.SuspendReason = until, reason
		}
		c.AuthFailures = failures
		if failures >= AuthPauseFailures {
			c.AuthPausedKeyVersion = version
		}
		if adsUntil != nil && adsUntil.After(now) {
			c.AdsSuspendedUntil = adsUntil
		}
		c.AdsAuthFailures = adsFailures
		if adsFailures >= AuthPauseFailures {
			c.AdsAuthPausedKeyVersion = adsVersion
		}
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return StatusReport{}, fmt.Errorf("store: status state: %w", err)
	}

	rows, err = t.pool.Query(ctx, `
		SELECT company_id, marketplace_account_id, endpoint, COALESCE(rps_override, 0)::float8, COALESCE(rps_current, 0)::float8
		FROM connection_limit
		WHERE (company_id, marketplace_account_id) IN (SELECT * FROM unnest($1::uuid[], $2::uuid[]))
		ORDER BY 1, 2, 3`, companies, accounts)
	if err != nil {
		return StatusReport{}, fmt.Errorf("store: status limits: %w", err)
	}
	for rows.Next() {
		var l Limit
		if err := rows.Scan(&l.Account.CompanyID, &l.Account.AccountID, &l.Endpoint, &l.Override, &l.Current); err != nil {
			rows.Close()
			return StatusReport{}, fmt.Errorf("store: status limits scan: %w", err)
		}
		c := cabinets[l.Account]
		c.Limits = append(c.Limits, l)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return StatusReport{}, fmt.Errorf("store: status limits: %w", err)
	}

	rows, err = t.pool.Query(ctx, `
		SELECT company_id, marketplace_account_id, outcome, sum(attempts)::int FROM attempt_outcome_hour
		WHERE (company_id, marketplace_account_id) IN (SELECT * FROM unnest($1::uuid[], $2::uuid[]))
		  AND hour >= date_trunc('hour', $3::timestamptz) - interval '23 hours'
		GROUP BY 1, 2, 3`, companies, accounts, now)
	if err != nil {
		return StatusReport{}, fmt.Errorf("store: status outcomes: %w", err)
	}
	for rows.Next() {
		var ref account.Ref
		var outcome string
		var n int
		if err := rows.Scan(&ref.CompanyID, &ref.AccountID, &outcome, &n); err != nil {
			rows.Close()
			return StatusReport{}, fmt.Errorf("store: status outcomes scan: %w", err)
		}
		cabinets[ref].Outcomes24h[outcome] = n
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return StatusReport{}, fmt.Errorf("store: status outcomes: %w", err)
	}

	for k, r := range resources {
		c := cabinets[k.ref]
		c.Resources = append(c.Resources, *r)
	}
	for _, ref := range refs {
		c := cabinets[ref]
		sort.Slice(c.Resources, func(i, j int) bool { return c.Resources[i].ResourceType < c.Resources[j].ResourceType })
		report.Cabinets = append(report.Cabinets, *c)
	}
	return report, nil
}

// inWindow — период задачи в окне отчёта (param — YYYY-MM-DD начала окна):
// справочник, день не раньше начала окна или месяц начала окна и позже.
func inWindow(param string) string {
	return `(period_key = 'ref' OR period_key >= CASE WHEN length(period_key) = 7 THEN left(` + param + `, 7) ELSE ` + param + ` END)`
}

// statusCabinets — страница кабинетов отчёта по marketplace_account_id.
// Возвращает курсор следующей страницы, если она есть.
func (t *Tasks) statusCabinets(ctx context.Context, windowFrom string, page StatusPage) ([]account.Ref, string, error) {
	var cursor *string
	if page.Cursor != "" {
		cursor = &page.Cursor
	}
	rows, err := t.pool.Query(ctx, `
		SELECT company_id, marketplace_account_id FROM (
			SELECT company_id, marketplace_account_id FROM fetch_task WHERE `+inWindow("$2")+`
			UNION SELECT company_id, marketplace_account_id FROM connection_state
			UNION SELECT company_id, marketplace_account_id FROM connection_limit
		) c
		WHERE $1::uuid IS NULL OR marketplace_account_id > $1::uuid
		ORDER BY marketplace_account_id
		LIMIT $3`, cursor, windowFrom, page.Limit+1)
	if err != nil {
		return nil, "", fmt.Errorf("store: status cabinets: %w", err)
	}
	defer rows.Close()
	refs := make([]account.Ref, 0, page.Limit+1)
	for rows.Next() {
		var ref account.Ref
		if err := rows.Scan(&ref.CompanyID, &ref.AccountID); err != nil {
			return nil, "", fmt.Errorf("store: status cabinets scan: %w", err)
		}
		refs = append(refs, ref)
	}
	if err := rows.Err(); err != nil {
		return nil, "", fmt.Errorf("store: status cabinets: %w", err)
	}
	if len(refs) <= page.Limit {
		return refs, "", nil
	}
	refs = refs[:page.Limit]
	return refs, refs[len(refs)-1].AccountID, nil
}

// stoppedResources — ресурсы, снятые у Ozon; их число ограничено набором
// ресурсов сервиса.
func (t *Tasks) stoppedResources(ctx context.Context, report *StatusReport) error {
	rows, err := t.pool.Query(ctx, `SELECT resource_type FROM resource_stop ORDER BY 1`)
	if err != nil {
		return fmt.Errorf("store: status stops: %w", err)
	}
	defer rows.Close()
	for rows.Next() {
		var r string
		if err := rows.Scan(&r); err != nil {
			return fmt.Errorf("store: status stops scan: %w", err)
		}
		report.StoppedResources = append(report.StoppedResources, r)
	}
	if err := rows.Err(); err != nil {
		return fmt.Errorf("store: status stops: %w", err)
	}
	return nil
}
