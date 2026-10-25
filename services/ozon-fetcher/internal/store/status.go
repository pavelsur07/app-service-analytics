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

// StatusAllConnections собирает отчёт; пропуски считаются по периодам >=
// windowFrom. Межарендаторное чтение операционной задачи (CLAUDE.md §1,
// ADR-043): состояние загрузки сервиса по подключениям всех компаний для
// /internal/fetch-status.
func (t *Tasks) StatusAllConnections(ctx context.Context, now time.Time, windowFrom string) (StatusReport, error) {
	type resourceKey struct {
		ref      account.Ref
		resource string
	}
	cabinets := map[account.Ref]*CabinetStatus{}
	cab := func(ref account.Ref) *CabinetStatus {
		if c, ok := cabinets[ref]; ok {
			return c
		}
		c := &CabinetStatus{CompanyID: ref.CompanyID, AccountID: ref.AccountID, Outcomes24h: map[string]int{}}
		cabinets[ref] = c
		return c
	}
	resources := map[resourceKey]*ResourceStatus{}
	res := func(ref account.Ref, resource string) *ResourceStatus {
		k := resourceKey{ref, resource}
		if r, ok := resources[k]; ok {
			return r
		}
		r := &ResourceStatus{ResourceType: resource, ByStatus: map[string]int{}, MissingPeriods: []string{}}
		resources[k] = r
		cab(ref)
		return r
	}

	rows, err := t.pool.Query(ctx, `
		SELECT company_id, marketplace_account_id, resource_type, status, count(*), max(last_success_at),
		       count(*) FILTER (WHERE revision > 1)
		FROM fetch_task GROUP BY 1, 2, 3, 4`)
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

	rows, err = t.pool.Query(ctx, `
		SELECT company_id, marketplace_account_id, resource_type, period_key FROM fetch_task
		WHERE status <> 'done' AND period_key <> 'ref'
		  AND period_key >= CASE WHEN length(period_key) = 7 THEN left($1, 7) ELSE $1 END -- YYYY-MM и YYYY-MM-DD
		ORDER BY company_id, marketplace_account_id, resource_type, period_key`, windowFrom)
	if err != nil {
		return StatusReport{}, fmt.Errorf("store: status missing: %w", err)
	}
	for rows.Next() {
		var ref account.Ref
		var resource, period string
		if err := rows.Scan(&ref.CompanyID, &ref.AccountID, &resource, &period); err != nil {
			rows.Close()
			return StatusReport{}, fmt.Errorf("store: status missing scan: %w", err)
		}
		r := res(ref, resource)
		r.MissingCount++
		if len(r.MissingPeriods) < MaxMissingListed {
			r.MissingPeriods = append(r.MissingPeriods, period)
		}
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return StatusReport{}, fmt.Errorf("store: status missing: %w", err)
	}

	rows, err = t.pool.Query(ctx, `
		SELECT company_id, marketplace_account_id, suspended_until, COALESCE(suspend_reason, ''), auth_failures,
		       auth_key_version, ads_suspended_until, ads_auth_failures, ads_auth_key_version
		FROM connection_state`)
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
		c := cab(ref)
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

	limits, err := t.LoadLimitsAllConnections(ctx)
	if err != nil {
		return StatusReport{}, err
	}
	for _, l := range limits {
		c := cab(l.Account)
		c.Limits = append(c.Limits, l)
	}

	rows, err = t.pool.Query(ctx, `
		SELECT company_id, marketplace_account_id, COALESCE(outcome, 'running'), count(*) FROM fetch_attempt
		WHERE started_at >= $1 GROUP BY 1, 2, 3`, now.Add(-24*time.Hour))
	if err != nil {
		return StatusReport{}, fmt.Errorf("store: status attempts: %w", err)
	}
	for rows.Next() {
		var ref account.Ref
		var outcome string
		var n int
		if err := rows.Scan(&ref.CompanyID, &ref.AccountID, &outcome, &n); err != nil {
			rows.Close()
			return StatusReport{}, fmt.Errorf("store: status attempts scan: %w", err)
		}
		cab(ref).Outcomes24h[outcome] = n
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return StatusReport{}, fmt.Errorf("store: status attempts: %w", err)
	}

	report := StatusReport{StoppedResources: []string{}}
	rows, err = t.pool.Query(ctx, `SELECT resource_type FROM resource_stop ORDER BY 1`)
	if err != nil {
		return StatusReport{}, fmt.Errorf("store: status stops: %w", err)
	}
	for rows.Next() {
		var r string
		if err := rows.Scan(&r); err != nil {
			rows.Close()
			return StatusReport{}, fmt.Errorf("store: status stops scan: %w", err)
		}
		report.StoppedResources = append(report.StoppedResources, r)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return StatusReport{}, fmt.Errorf("store: status stops: %w", err)
	}

	for k, r := range resources {
		c := cabinets[k.ref]
		c.Resources = append(c.Resources, *r)
	}
	for _, c := range cabinets {
		sort.Slice(c.Resources, func(i, j int) bool { return c.Resources[i].ResourceType < c.Resources[j].ResourceType })
		if c.Resources == nil {
			c.Resources = []ResourceStatus{}
		}
		if c.Limits == nil {
			c.Limits = []Limit{}
		}
		report.Cabinets = append(report.Cabinets, *c)
	}
	sort.Slice(report.Cabinets, func(i, j int) bool {
		a, b := report.Cabinets[i], report.Cabinets[j]
		return a.CompanyID < b.CompanyID || (a.CompanyID == b.CompanyID && a.AccountID < b.AccountID)
	})
	if report.Cabinets == nil {
		report.Cabinets = []CabinetStatus{}
	}
	return report, nil
}
