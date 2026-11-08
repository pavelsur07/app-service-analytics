package store

import (
	"context"
	"encoding/json"
	"strings"
	"testing"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/observation"
)

func TestInspectionPaginationScopeAndDeadline(t *testing.T) {
	s, _ := testStore(t)
	ctx := context.Background()
	now := time.Now().UTC().Truncate(time.Second)
	first, second := policy(now), policy(now)
	if err := s.Apply(ctx, []observation.Policy{first, second}, now); err != nil {
		t.Fatal(err)
	}
	seen := map[string]bool{}
	cursor := ""
	for range 2 {
		page, err := s.Inspect(ctx, cursor, 1, now.Add(36*time.Second))
		if err != nil || len(page.Items) != 1 {
			t.Fatal("inspection page", err)
		}
		item := page.Items[0]
		if seen[item.PolicyID] || item.Signal != "deadline_missed" {
			t.Fatal("duplicate or missed deadline not detected")
		}
		seen[item.PolicyID] = true
		cursor = page.NextCursor
		body, err := json.Marshal(item)
		if err != nil || strings.Contains(string(body), "daily_limit") || strings.Contains(string(body), "effective") || strings.Contains(string(body), "credential") {
			t.Fatal("unexpected diagnostic payload")
		}
	}
	if cursor != "" || !seen[first.ID] || !seen[second.ID] {
		t.Fatal("pagination lost company")
	}
	if _, err := s.Inspect(ctx, "bad", 100, now); err == nil {
		t.Fatal("invalid cursor accepted")
	}
	if _, err := s.Inspect(ctx, "", 101, now); err == nil {
		t.Fatal("unbounded list accepted")
	}
	_, err := s.pool.Exec(ctx, `UPDATE ads_policy SET runtime='{"error":"private-secret"}' WHERE company_id=$1 AND account_id=$2 AND policy_id=$3`, first.CompanyID, first.AccountID, first.ID)
	if err != nil {
		t.Fatal(err)
	}
	page, err := s.Inspect(ctx, "", 100, now)
	if err != nil {
		t.Fatal(err)
	}
	for _, item := range page.Items {
		if item.PolicyID == first.ID && item.Signal != "invalid_runtime_error" {
			t.Fatal("untrusted runtime error exposed")
		}
	}
}
func TestInspectionSignals(t *testing.T) {
	now := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	old, future := now.Add(-700*time.Second), now.Add(20*time.Second)
	for _, tc := range []struct {
		name             string
		enabled          bool
		permission, tick time.Time
		at               *time.Time
		source, want     string
	}{
		{"disabled", false, now, now, nil, "", "disabled"},
		{"clock", true, now.Add(time.Minute), now, &future, "", "clock_skew"},
		{"grant", true, now, now, nil, "", "permission_expired"},
		{"awaiting", true, now.Add(time.Minute), now, nil, "", "awaiting_observation"},
		{"stale", true, now.Add(time.Minute), now, &old, "", "observation_stale"},
		{"rate", true, now.Add(time.Minute), now, &now, "rate_limited", "rate_limited"},
		{"observed", true, now.Add(time.Minute), now, &now, "", "observed_source_age_unknown"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			item := Inspection{Enabled: tc.enabled, PermissionUntil: tc.permission, NextTick: tc.tick, EvaluatedAt: tc.at}
			if inspectionSignal(item, tc.source, 600, now) != tc.want {
				t.Fatal("wrong diagnostic signal")
			}
		})
	}
}
