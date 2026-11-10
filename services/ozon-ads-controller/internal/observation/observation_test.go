package observation

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"math"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func testPolicy() Policy {
	return Policy{CompanyID: "019a60df-1000-7000-8000-000000000001", AccountID: "019a60df-1000-7000-8000-000000000002", ID: "019a60df-1000-7000-8000-000000000003", CampaignID: "49761882", Version: 1, Mode: "observe", Enabled: true, DailyLimit: Money{10000, "RUB"}, Timezone: "Europe/Moscow", Start: "09:00", Stop: "21:00", PollSeconds: 600, PermissionUntil: time.Date(2026, 11, 8, 20, 0, 0, 0, time.UTC)}
}
func TestMoney(t *testing.T) {
	for _, tc := range []struct {
		raw  string
		want int64
	}{{"0", 0}, {"1,03", 103}, {"1.3", 130}, {"92233720368547758.07", math.MaxInt64}} {
		got, err := ParseMoney(tc.raw, "RUB")
		if err != nil || got.Minor != tc.want {
			t.Fatalf("%s: %v %v", tc.raw, got, err)
		}
	}
	for _, raw := range []string{"", "-1", "+1", "1.001", "1e2", " 1", "1 ", "NaN", "00.1", "92233720368547758.08"} {
		if _, err := ParseMoney(raw, "RUB"); err == nil {
			t.Fatalf("accepted %q", raw)
		}
	}
	if _, err := ParseMoney("1", "USD"); err == nil {
		t.Fatal("currency")
	}
	if _, err := (Money{math.MaxInt64, "RUB"}).Add(Money{1, "RUB"}); err == nil {
		t.Fatal("overflow")
	}
	if _, err := (Money{1, "RUB"}).Add(Money{1, "USD"}); err == nil {
		t.Fatal("mixed_currency")
	}
}
func TestReserveRoundsUpExactly(t *testing.T) {
	at := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	samples := []RateSample{{at, Money{0, "RUB"}}, {at.Add(3 * time.Second), Money{1, "RUB"}}, {at.Add(6 * time.Second), Money{2, "RUB"}}}
	reserve, err := EstimateReserve(samples, 10*time.Second, 6*time.Second)
	if err != nil || reserve.Minor != 4 {
		t.Fatalf("ceil(10/3): %v %v", reserve, err)
	}
	for _, mutate := range []func([]RateSample) []RateSample{func(s []RateSample) []RateSample { return s[:2] }, func(s []RateSample) []RateSample { s[1].At = s[0].At; return s }, func(s []RateSample) []RateSample { s[2].Spend.Minor = 0; return s }, func(s []RateSample) []RateSample { s[1].Spend.Currency = "USD"; return s }} {
		if _, err = EstimateReserve(mutate(append([]RateSample{}, samples...)), time.Second, time.Second); err == nil {
			t.Fatal("invalid_history")
		}
	}
}
func TestDecisionBoundaries(t *testing.T) {
	now := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	for _, tc := range []struct {
		name           string
		change         func(*Policy, *Snapshot, **Snapshot, **Money, **Money)
		action, reason string
	}{
		{"margin", func(_ *Policy, _ *Snapshot, _ **Snapshot, _ **Money, _ **Money) {}, "would_run", "conditions_met"},
		{"unknown_reserve", func(_ *Policy, _ *Snapshot, _ **Snapshot, _ **Money, r **Money) { *r = nil }, "blocked", "reserve_unavailable"},
		{"new_day", func(_ *Policy, s *Snapshot, _ **Snapshot, _ **Money, _ **Money) { s.Day = "2026-11-07" }, "blocked", "primary_unavailable"},
		{"empty_is_unknown", func(_ *Policy, s *Snapshot, _ **Snapshot, _ **Money, _ **Money) {
			s.Complete = false
			s.Spend.Minor = 0
		}, "blocked", "primary_unavailable"},
		{"equal_budget", func(_ *Policy, s *Snapshot, _ **Snapshot, _ **Money, _ **Money) { s.Spend.Minor = 10000 }, "would_stop", "budget_reached"},
		{"equal_margin", func(_ *Policy, s *Snapshot, _ **Snapshot, _ **Money, _ **Money) { s.Spend.Minor = 9900 }, "would_stop", "budget_margin"},
		{"maximum_sku", func(_ *Policy, s *Snapshot, k **Snapshot, _ **Money, _ **Money) {
			copy := *s
			copy.Spend.Minor = 10001
			*k = &copy
		}, "would_stop", "budget_reached"},
		{"correction", func(_ *Policy, _ *Snapshot, _ **Snapshot, p **Money, _ **Money) { *p = &Money{10000, "RUB"} }, "would_stop", "budget_reached"},
		{"expired", func(p *Policy, _ *Snapshot, _ **Snapshot, _ **Money, _ **Money) { p.PermissionUntil = now }, "blocked", "permission_expired"},
		{"stopped", func(p *Policy, _ *Snapshot, _ **Snapshot, _ **Money, _ **Money) { p.Stop = "13:00" }, "would_stop", "outside_window"},
		{"not_started", func(p *Policy, _ *Snapshot, _ **Snapshot, _ **Money, _ **Money) { p.Start = "14:00" }, "would_stop", "outside_window"},
		{"disabled", func(p *Policy, _ *Snapshot, _ **Snapshot, _ **Money, _ **Money) { p.Enabled = false }, "blocked", "policy_disabled"},
		{"wrong_currency", func(_ *Policy, s *Snapshot, _ **Snapshot, _ **Money, _ **Money) { s.Spend.Currency = "USD" }, "blocked", "primary_unavailable"},
		{"old_sku", func(_ *Policy, s *Snapshot, k **Snapshot, _ **Money, _ **Money) {
			copy := *s
			copy.FetchedAt = now.Add(-time.Hour)
			*k = &copy
		}, "blocked", "sku_incomparable"},
		{"future_source", func(_ *Policy, s *Snapshot, _ **Snapshot, _ **Money, _ **Money) { s.FetchedAt = now.Add(time.Second) }, "blocked", "primary_unavailable"},
		{"huge_reserve", func(_ *Policy, _ *Snapshot, _ **Snapshot, _ **Money, r **Money) { *r = &Money{math.MaxInt64, "RUB"} }, "would_stop", "budget_margin"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			p := testPolicy()
			s := Snapshot{CampaignID: p.CampaignID, Day: "2026-11-08", Spend: Money{1000, "RUB"}, FetchedAt: now, Complete: true}
			var sku *Snapshot
			var prev *Money
			reserve := &Money{100, "RUB"}
			tc.change(&p, &s, &sku, &prev, &reserve)
			d := Evaluate(p, now, s, sku, prev, reserve)
			if d.Action != tc.action || d.Reason != tc.reason || d.SourceAgeKnown {
				t.Fatalf("%+v", d)
			}
		})
	}
}
func TestPolicyValidation(t *testing.T) {
	for _, change := range []func(*Policy){func(p *Policy) { p.Mode = "control" }, func(p *Policy) { p.Start = "21:00" }, func(p *Policy) { p.Start = "+1:00" }, func(p *Policy) { p.PollSeconds = 599 }, func(p *Policy) { p.CompanyID = "other" }, func(p *Policy) { p.CampaignID = "01" }, func(p *Policy) { p.DailyLimit.Minor = 0 }} {
		p := testPolicy()
		change(&p)
		if p.Validate() == nil {
			t.Fatal("invalid_policy")
		}
	}
	p := testPolicy()
	day, within := p.Window(time.Date(2026, 11, 7, 21, 0, 0, 0, time.UTC))
	if day != "2026-11-08" || within {
		t.Fatal(day, within)
	}
}

const fixtureRoot = "/api/tests/Fixtures/Marketplace/ozon/performance"

func fixture(t *testing.T, dir, name string) []byte {
	t.Helper()
	root, err := os.OpenRoot(fixtureRoot)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = root.Close() }()
	body, err := root.ReadFile(filepath.Join(dir, name))
	if err != nil {
		t.Fatal(err)
	}
	return body
}
func TestRealFixtureManifests(t *testing.T) {
	for _, dir := range []string{"cost-control-2026-11-08", "methods-2026-11-08"} {
		var manifest []struct {
			File, SHA string
			Bytes     int
		}
		var rows []struct {
			File  string `json:"file"`
			SHA   string `json:"sha256"`
			Bytes int    `json:"bytes"`
		}
		if err := json.Unmarshal(fixture(t, dir, "manifest.json"), &rows); err != nil {
			t.Fatal(err)
		}
		for _, row := range rows {
			manifest = append(manifest, struct {
				File, SHA string
				Bytes     int
			}{row.File, row.SHA, row.Bytes})
		}
		for _, row := range manifest {
			body := fixture(t, dir, row.File)
			hash := sha256.Sum256(body)
			if len(body) != row.Bytes || hex.EncodeToString(hash[:]) != row.SHA {
				t.Fatal(dir, row.File, "hash mismatch")
			}
		}
	}
}
func TestRealParsersAndDiscrepancy(t *testing.T) {
	now := time.Date(2026, 11, 8, 6, 0, 0, 0, time.UTC)
	root := "methods-2026-11-08"
	aggregate, err := ParseAggregate(fixture(t, root, "product-json-2026-11-08.json"), "2026-11-08", now)
	if err != nil {
		t.Fatal(err)
	}
	if aggregate["14275771"].Spend.Minor != 103 {
		t.Fatal(aggregate["14275771"])
	}
	sku, err := ParseSKU(fixture(t, root, "series-1-sku-before.json"), []string{"14275771", "16017246", "23253271", "24147313", "48663607", "48719687", "48824224", "48906588", "49322513", "49761882"}, "2026-11-08", now)
	// Verify the exact requested set from the fixture instead of assuming IDs.
	if err != nil {
		var raw struct {
			Rows []struct {
				ID string `json:"campaignId"`
			} `json:"rows"`
		}
		if json.Unmarshal(fixture(t, root, "series-1-sku-before.json"), &raw) != nil {
			t.Fatal(err)
		}
		ids := []string{}
		seen := map[string]bool{}
		for _, r := range raw.Rows {
			if !seen[r.ID] {
				ids = append(ids, r.ID)
				seen[r.ID] = true
			}
		}
		sku, err = ParseSKU(fixture(t, root, "series-1-sku-before.json"), ids, "2026-11-08", now)
	}
	if err != nil || sku["14275771"].Spend.Minor != 463 {
		t.Fatalf("%v %v", sku, err)
	}
	for _, day := range []string{"2026-11-08", "2026-11-07"} {
		if _, err = ParseSKU(fixture(t, "cost-control-2026-11-08", "statistics-products-sku-"+day+".json"), []string{"49761882"}, day, now); err != nil {
			t.Fatal(err)
		}
	}
	if _, err = ParseSKU(fixture(t, "cost-control-2026-11-08", "statistics-products-sku-2026-11-07.json"), []string{"49761882"}, "2026-11-08", now); err == nil {
		t.Fatal("yesterday accepted")
	}
	if _, err = ParseSKU([]byte(`{"rows":[]}`), []string{"49761882"}, "2026-11-08", now); err == nil {
		t.Fatal("empty accepted")
	}
	campaigns, err := ParseCampaigns(fixture(t, "cost-control-2026-11-08", "campaign-list.json"))
	if err != nil || len(campaigns) != 101 {
		t.Fatalf("campaigns %d %v", len(campaigns), err)
	}
	// Daily has another schema, expense is empty, old product CSV lacks a verified
	// day: none may enter the operational aggregate parser as a zero snapshot.
	for _, name := range []string{"statistics-daily-2026-11-08.json", "statistics-expense-2026-11-08.json", "statistics-campaign-product.csv"} {
		rows, parseErr := ParseAggregate(fixture(t, "cost-control-2026-11-08", name), "2026-11-08", now)
		if parseErr == nil && len(rows) > 0 {
			t.Fatal("unsupported source accepted", name)
		}
	}
	if rows, err := CSVData(fixture(t, root, "report-csv-download.csv")); err != nil || len(rows) == 0 {
		t.Fatalf("async CSV: %v %d", err, len(rows))
	}
}
func FuzzMoney(f *testing.F) {
	for _, s := range []string{"1.03", "92233720368547758.07", "1e9"} {
		f.Add(s)
	}
	f.Fuzz(func(t *testing.T, raw string) {
		money, err := ParseMoney(raw, "RUB")
		if err == nil && (money.Minor < 0 || money.Currency != "RUB") {
			t.Fatal(money)
		}
	})
}

func TestKnownDailySpendStopsEvenWhenCurrentSnapshotIsIncomplete(t *testing.T) {
	p := testPolicy()
	now := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	known := Money{Minor: p.DailyLimit.Minor, Currency: "RUB"}
	result := Evaluate(p, now, Snapshot{}, nil, &known, nil)
	if result.Action != "would_stop" || result.Reason != "budget_reached" || result.Effective == nil {
		t.Fatal(result)
	}
}
