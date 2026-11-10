package store

import (
	"context"
	"encoding/base64"
	"errors"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/observation"
)

// Inspection is an operational cross-tenant view. No runtime documents, money or credentials.
type Inspection struct {
	CompanyID       string     `json:"company_id"`
	AccountID       string     `json:"account_id"`
	PolicyID        string     `json:"policy_id"`
	Version         int64      `json:"version"`
	Enabled         bool       `json:"enabled"`
	NextTick        time.Time  `json:"next_tick"`
	NextPoll        time.Time  `json:"next_poll"`
	PermissionUntil time.Time  `json:"permission_until"`
	EvaluatedAt     *time.Time `json:"evaluated_at"`
	Signal          string     `json:"signal"`
}
type InspectionPage struct {
	Items      []Inspection `json:"items"`
	NextCursor string       `json:"next_cursor"`
}

// Inspect is deliberately operational/global, bounded and keyset ordered by the PK.
func (s *Store) Inspect(ctx context.Context, after string, limit int, now time.Time) (InspectionPage, error) {
	result := InspectionPage{Items: []Inspection{}}
	if limit < 1 || limit > 100 {
		return result, errors.New("invalid_limit")
	}
	if !ValidInspectionCursor(after) {
		return result, errors.New("invalid_cursor")
	}
	predicate := ""
	args := []any{limit + 1}
	if after != "" {
		raw, _ := base64.RawURLEncoding.DecodeString(after)
		parts := strings.Split(string(raw), ":")
		predicate = " WHERE (p.company_id,p.account_id,p.policy_id) > ($2::uuid,$3::uuid,$4::uuid)"
		args = append(args, parts[0], parts[1], parts[2])
	}
	rows, err := s.pool.Query(ctx, `SELECT p.company_id::text,p.account_id::text,p.policy_id::text,p.version,(p.document->>'enabled')::boolean,a.next_tick,a.next_poll,(p.document->>'permission_until')::timestamptz,(p.runtime->>'evaluated_at')::timestamptz,COALESCE(p.runtime->>'error',''),(p.document->>'poll_seconds')::int
 FROM ads_policy p JOIN ads_account a USING(company_id,account_id)`+predicate+` ORDER BY p.company_id,p.account_id,p.policy_id LIMIT $1`, args...)
	if err != nil {
		return result, err
	}
	defer rows.Close()
	for rows.Next() {
		var item Inspection
		var sourceError string
		var poll int
		if err = rows.Scan(&item.CompanyID, &item.AccountID, &item.PolicyID, &item.Version, &item.Enabled, &item.NextTick, &item.NextPoll, &item.PermissionUntil, &item.EvaluatedAt, &sourceError, &poll); err != nil {
			return result, err
		}
		item.Signal = inspectionSignal(item, sourceError, poll, now)
		result.Items = append(result.Items, item)
	}
	if err = rows.Err(); err != nil {
		return result, err
	}
	if len(result.Items) > limit {
		result.Items = result.Items[:limit]
		last := result.Items[limit-1]
		result.NextCursor = base64.RawURLEncoding.EncodeToString([]byte(last.CompanyID + ":" + last.AccountID + ":" + last.PolicyID))
	}
	return result, nil
}
func ValidInspectionCursor(cursor string) bool {
	if cursor == "" {
		return true
	}
	if len(cursor) > 160 {
		return false
	}
	raw, err := base64.RawURLEncoding.DecodeString(cursor)
	if err != nil {
		return false
	}
	parts := strings.Split(string(raw), ":")
	if len(parts) != 3 {
		return false
	}
	for _, id := range parts {
		if !observation.ValidUUID(id) {
			return false
		}
	}
	return true
}
func inspectionSignal(item Inspection, sourceError string, poll int, now time.Time) string {
	switch {
	case !item.Enabled:
		return "disabled"
	case item.EvaluatedAt != nil && item.EvaluatedAt.After(now.Add(5*time.Second)):
		return "clock_skew"
	case !now.Before(item.PermissionUntil):
		return "permission_expired"
	case item.NextTick.Before(now.Add(-35 * time.Second)):
		return "deadline_missed"
	}
	if sourceError != "" {
		switch sourceError {
		case "source_unavailable", "rate_limited", "permission_or_credentials_unavailable", "campaign_unsupported_or_missing", "campaign_state_blocked", "manual_pause":
			return sourceError
		default:
			return "invalid_runtime_error"
		}
	}
	if item.EvaluatedAt == nil {
		return "awaiting_observation"
	}
	if item.EvaluatedAt.Before(now.Add(-time.Duration(poll+35) * time.Second)) {
		return "observation_stale"
	}
	return "observed_source_age_unknown"
}
