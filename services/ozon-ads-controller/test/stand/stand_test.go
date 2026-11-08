package stand

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/control"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/delivery"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/observation"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/server"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/store"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/uuidv7"
)

func database(t *testing.T) (*store.Store, *pgx.Conn, string) {
	t.Helper()
	dsn := os.Getenv("TEST_DB_DSN")
	if dsn == "" {
		t.Skip("TEST_DB_DSN unavailable")
	}
	cfg, err := pgx.ParseConfig(dsn)
	if err != nil || cfg.Database != "ozon_ads_test" || cfg.User != "svc_ozon_ads" {
		t.Fatal("stand requires its dedicated test database/role")
	}
	ctx := context.Background()
	admin, err := pgx.Connect(ctx, dsn)
	if err != nil {
		t.Fatal("stand database unavailable")
	}
	schema := "stand_" + strings.ReplaceAll(uuidv7.New(time.Now()), "-", "")
	quoted := pgx.Identifier{schema}.Sanitize()
	if _, err = admin.Exec(ctx, "CREATE SCHEMA "+quoted); err != nil {
		t.Fatal(err)
	}
	u, err := url.Parse(dsn)
	if err != nil {
		t.Fatal("invalid stand DSN")
	}
	query := u.Query()
	query.Set("search_path", schema)
	u.RawQuery = query.Encode()
	isolated := u.String()
	t.Cleanup(func() {
		if _, dropErr := admin.Exec(context.Background(), "DROP SCHEMA "+quoted+" CASCADE"); dropErr != nil {
			t.Error(dropErr)
		}
		if closeErr := admin.Close(context.Background()); closeErr != nil {
			t.Error(closeErr)
		}
	})
	if err = store.Migrate(ctx, isolated, false); err != nil {
		t.Fatal(err)
	}
	db, err := store.Open(ctx, isolated)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(db.Close)
	return db, admin, isolated
}

type mock struct {
	snapshotHook func(string)
	owners       map[string]int
	calls        atomic.Int64
	mu           sync.Mutex
	statuses     map[string]int
	slow         string
	empty        bool
	yesterday    bool
	forbidden    atomic.Bool
}

func (m *mock) serve(w http.ResponseWriter, r *http.Request) {
	m.calls.Add(1)
	timer := time.NewTimer(5 * time.Millisecond)
	defer timer.Stop()
	select {
	case <-r.Context().Done():
		return
	case <-timer.C:
	}
	w.Header().Set("Content-Type", "application/json")
	if r.URL.Path == "/api/client/token" && r.Method == http.MethodPost {
		var body struct {
			ID string `json:"client_id"`
		}
		if json.NewDecoder(r.Body).Decode(&body) != nil || m.owners[body.ID] == 0 {
			w.WriteHeader(401)
			return
		}
		//nolint:gosec // G117: synthetic OAuth response from localhost httptest, never real credentials.
		if err := json.NewEncoder(w).Encode(struct {
			Token   string `json:"access_token"`
			Expires int    `json:"expires_in"`
			Type    string `json:"token_type"`
		}{"stand-" + body.ID, 3600, "Bearer"}); err != nil {
			return
		}
		return
	}
	owner := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer stand-")
	number := m.owners[owner]
	if number == 0 {
		w.WriteHeader(403)
		return
	}
	if owner == m.slow {
		select {
		case <-r.Context().Done():
			return
		case <-time.After(11 * time.Second):
		}
	}
	m.mu.Lock()
	status := m.statuses[owner]
	m.mu.Unlock()
	if status != 0 {
		if status == 429 {
			w.Header().Set("Retry-After", "3600")
		}
		w.WriteHeader(status)
		return
	}
	if m.empty && strings.Contains(r.URL.Path, "statistics") {
		_, _ = w.Write([]byte(`{"rows":[]}`))
		return
	}
	switch {
	case r.Method == http.MethodGet && r.URL.Path == "/api/client/campaign":
		rows := make([]struct {
			ID      string `json:"id"`
			State   string `json:"state"`
			Payment string `json:"PaymentType"`
			Object  string `json:"advObjectType"`
		}, 0, 10)
		for i := 1; i <= 10; i++ {
			rows = append(rows, struct {
				ID      string `json:"id"`
				State   string `json:"state"`
				Payment string `json:"PaymentType"`
				Object  string `json:"advObjectType"`
			}{strconv.Itoa(i), "CAMPAIGN_STATE_RUNNING", "CPC", "SKU"})
		}
		_ = json.NewEncoder(w).Encode(struct {
			List any `json:"list"`
		}{rows})
	case r.Method == http.MethodGet && r.URL.Path == "/api/client/statistics/campaign/product/json":
		rows := make([]observation.AggregateRow, 0, 10)
		for i := 1; i <= 10; i++ {
			rows = append(rows, observation.AggregateRow{ID: strconv.Itoa(i), ObjectType: "SKU", Status: "CAMPAIGN_STATE_RUNNING", MoneySpent: fmt.Sprintf("%d.03", number)})
		}
		_ = json.NewEncoder(w).Encode(struct {
			Rows []observation.AggregateRow `json:"rows"`
		}{rows})
		if m.snapshotHook != nil {
			m.snapshotHook("aggregate")
		}
	case r.Method == http.MethodPost && r.URL.Path == "/api/client/statistics/products/sku":
		var body struct {
			IDs  []string `json:"campaignIds"`
			From string   `json:"dateFrom"`
			To   string   `json:"dateTo"`
		}
		if json.NewDecoder(r.Body).Decode(&body) != nil || len(body.IDs) != 10 || body.From != body.To {
			w.WriteHeader(422)
			return
		}
		type row struct {
			Campaign string `json:"campaignId"`
			Day      string `json:"date"`
			SKU      string `json:"sku"`
			Expense  string `json:"expense"`
		}
		rows := make([]row, 0, 10)
		for _, id := range body.IDs {
			day := body.From
			if m.yesterday {
				parsed, _ := time.Parse(time.DateOnly, day)
				day = parsed.AddDate(0, 0, -1).Format(time.DateOnly)
			}
			rows = append(rows, row{id, day, id, fmt.Sprintf("%d.63", number)})
		}
		_ = json.NewEncoder(w).Encode(struct {
			Rows []row `json:"rows"`
		}{rows})
		if m.snapshotHook != nil {
			m.snapshotHook("sku")
		}
	default:
		m.forbidden.Store(true)
		w.WriteHeader(405)
	}
}

func configuration(accounts int, at time.Time) (delivery.Configuration, map[string]int, map[string]int64) {
	c := delivery.Configuration{Credentials: map[string]ozon.Credentials{}}
	owners := map[string]int{}
	expected := map[string]int64{}
	for a := 1; a <= accounts; a++ {
		company, account := uuidv7.New(at), uuidv7.New(at)
		owners[account] = a
		c.Credentials[delivery.AccountKey(company, account)] = ozon.Credentials{CompanyID: company, AccountID: account, ClientID: account, ClientSecret: "synthetic-stand-secret", UpdatedAt: at}
		for campaign := 1; campaign <= 10; campaign++ {
			id := uuidv7.New(at)
			c.Policies = append(c.Policies, observation.Policy{CompanyID: company, AccountID: account, ID: id, CampaignID: strconv.Itoa(campaign), Version: 1, Mode: "observe", Enabled: true, DailyLimit: observation.Money{Minor: 10000000, Currency: "RUB"}, Start: "00:00", Stop: "23:59", Timezone: "Europe/Moscow", PollSeconds: 600, PermissionUntil: at.Add(120 * time.Second)})
			expected[id] = int64(a)*100 + 63
		}
	}
	return c, owners, expected
}

func TestObserveLoad(t *testing.T) {
	for _, profile := range []struct {
		name               string
		accounts, replicas int
		bound              time.Duration
	}{{"normal", 10, 1, 5 * time.Second}, {"peak", 100, 1, 35 * time.Second}, {"two_replicas", 100, 2, 35 * time.Second}} {
		t.Run(profile.name, func(t *testing.T) {
			db, _, dsn := database(t)
			start := time.Now()
			base := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
			now := func() time.Time { return base.Add(time.Since(start)) }
			configuration, owners, expected := configuration(profile.accounts, now())
			mock := &mock{owners: owners, statuses: map[string]int{}}
			httpServer := httptest.NewServer(http.HandlerFunc(mock.serve))
			t.Cleanup(httpServer.Close)
			quota := control.GuardQuota(db, now, time.Now)
			reader, err := ozon.New(httpServer.Client(), httpServer.URL, quota, now)
			if err != nil {
				t.Fatal(err)
			}
			engine := &control.Engine{Store: db, Reader: reader, Now: now, ClockOK: quota.Check}
			applied := time.Now()
			if err = engine.Install(context.Background(), configuration, now()); err != nil {
				t.Fatal(err)
			}
			applyDuration := time.Since(applied)
			if applyDuration > 5*time.Second {
				t.Fatal("configuration SLO exceeded")
			}
			ctx, cancel := context.WithCancel(context.Background())
			var group sync.WaitGroup
			var mu sync.Mutex
			completed := map[string]bool{}
			latencies := []int64{}
			done := make(chan struct{})
			due := applied
			failures := 0
			callback := func(claim *store.Claim, cycleErr error, _ time.Duration) {
				if ctx.Err() != nil {
					return
				}
				mu.Lock()
				defer mu.Unlock()
				if cycleErr != nil || claim == nil {
					failures++
					return
				}
				key := claim.CompanyID + ":" + claim.AccountID
				if completed[key] {
					failures++
					return
				}
				completed[key] = true
				// Each account's campaigns commit together; report every campaign sample.
				for range claim.Items {
					latencies = append(latencies, time.Since(due).Milliseconds())
				}
				if len(completed) == profile.accounts {
					close(done)
				}
			}
			engines := []*control.Engine{engine}
			for replica := 1; replica < profile.replicas; replica++ {
				secondDB, openErr := store.Open(context.Background(), dsn)
				if openErr != nil {
					t.Fatal(openErr)
				}
				t.Cleanup(secondDB.Close)
				secondQuota := control.GuardQuota(secondDB, now, time.Now)
				secondReader, readerErr := ozon.New(httpServer.Client(), httpServer.URL, secondQuota, now)
				if readerErr != nil {
					t.Fatal(readerErr)
				}
				secondEngine := &control.Engine{Store: secondDB, Reader: secondReader, Now: now, ClockOK: secondQuota.Check}
				if installErr := secondEngine.Install(context.Background(), configuration, now()); installErr != nil {
					t.Fatal(installErr)
				}
				engines = append(engines, secondEngine)
			}
			for _, replicaEngine := range engines {
				group.Go(func() {
					if workerErr := control.Workers(ctx, replicaEngine.Store, replicaEngine, 4, now, time.Now, uuidv7.New, callback); workerErr != nil {
						t.Error(workerErr)
					}
				})
			}
			t.Cleanup(func() { cancel(); group.Wait() })
			select {
			case <-done:
			case <-time.After(profile.bound + time.Second):
				t.Fatal("load deadline exceeded")
			}
			// UI/PHP and Messenger are deliberately absent; pending publication must not impede polling.
			time.Sleep(2100 * time.Millisecond)
			cancel()
			group.Wait()
			if failures != 0 || mock.forbidden.Load() || mock.calls.Load() != int64(profile.accounts*4) {
				t.Fatal("errors, forbidden requests, duplicate or early polling")
			}
			sort.Slice(latencies, func(i, j int) bool { return latencies[i] < latencies[j] })
			percentile := func(p int) int64 { return latencies[(len(latencies)*p+99)/100-1] }
			if len(latencies) != profile.accounts*10 || percentile(99) > profile.bound.Milliseconds() || latencies[len(latencies)-1] > profile.bound.Milliseconds() {
				t.Fatal("latency SLO exceeded")
			}
			verify(t, dsn, expected, 1, "blocked")
			pending, pendingErr := db.Pending(context.Background(), 100)
			if pendingErr != nil || len(pending) != 100 {
				t.Fatal("PHP outage lost publication", pendingErr)
			}
			t.Logf("STAND profile=%s accounts=%d policies=%d replicas=%d workers_per_replica=4 samples=%d apply_ms=%d p50_ms=%d p95_ms=%d p99_ms=%d max_ms=%d http=%d", profile.name, profile.accounts, len(expected), profile.replicas, len(latencies), applyDuration.Milliseconds(), percentile(50), percentile(95), percentile(99), latencies[len(latencies)-1], mock.calls.Load())
			// A mass limit change must calculate hypothetical budget stops from
			// persisted same-day spend, without an early statistics request.
			massDue := time.Now()
			for i := range configuration.Policies {
				configuration.Policies[i].Version = 2
				configuration.Policies[i].DailyLimit.Minor = 1
				configuration.Policies[i].PermissionUntil = now().Add(120 * time.Second)
			}
			for _, replicaEngine := range engines {
				if installErr := replicaEngine.Install(context.Background(), configuration, now()); installErr != nil {
					t.Fatal(installErr)
				}
			}
			mu.Lock()
			completed = map[string]bool{}
			latencies = nil
			done = make(chan struct{})
			failures = 0
			due = massDue
			mu.Unlock()
			ctx, cancel = context.WithCancel(context.Background())
			for _, replicaEngine := range engines {
				group.Go(func() {
					if runErr := control.Workers(ctx, replicaEngine.Store, replicaEngine, 4, now, time.Now, uuidv7.New, callback); runErr != nil {
						t.Error(runErr)
					}
				})
			}
			select {
			case <-done:
			case <-time.After(profile.bound + time.Second):
				t.Fatal("mass hypothetical stop deadline exceeded")
			}
			cancel()
			group.Wait()
			sort.Slice(latencies, func(i, j int) bool { return latencies[i] < latencies[j] })
			if failures != 0 || len(latencies) != len(expected) || latencies[len(latencies)-1] > profile.bound.Milliseconds() || mock.calls.Load() != int64(profile.accounts*4) {
				t.Fatal("mass stop SLO, duplicate or early HTTP")
			}
			verify(t, dsn, expected, 2, "would_stop")
			t.Logf("STAND profile=%s phase=budget_stop samples=%d p50_ms=%d p95_ms=%d p99_ms=%d max_ms=%d http_delta=0", profile.name, len(latencies), percentile(50), percentile(95), percentile(99), latencies[len(latencies)-1])

			// Healthy HTTP with stopped workers must still reveal each missed policy deadline.
			for i := range configuration.Policies {
				configuration.Policies[i].PermissionUntil = now().Add(120 * time.Second)
			}
			if err = engine.Install(context.Background(), configuration, now()); err != nil {
				t.Fatal(err)
			}
			handler := server.Handler(db, "stand-monitor-token-0123456789abcdef", true, db, func() time.Time { return now().Add(36 * time.Second) })
			request := httptest.NewRequestWithContext(context.Background(), "GET", "/internal/observations?limit=100", nil)
			request.Header.Set("Authorization", "Bearer stand-monitor-token-0123456789abcdef")
			response := httptest.NewRecorder()
			handler.ServeHTTP(response, request)
			if response.Code != 200 || !strings.Contains(response.Body.String(), "deadline_missed") {
				t.Fatal("per-policy monitoring missed stopped workers")
			}
			health := httptest.NewRecorder()
			handler.ServeHTTP(health, httptest.NewRequestWithContext(context.Background(), "GET", "/health", nil))
			if health.Code != 200 {
				t.Fatal("heartbeat should still be alive")
			}
		})
	}
}

func verify(t *testing.T, dsn string, expected map[string]int64, expectedVersion int64, expectedAction string) {
	t.Helper()
	ctx := context.Background()
	db, err := pgx.Connect(ctx, dsn)
	if err != nil {
		t.Fatal("verification unavailable")
	}
	defer func() {
		if closeErr := db.Close(ctx); closeErr != nil {
			t.Error(closeErr)
		}
	}()
	rows, err := db.Query(ctx, `SELECT policy_id::text,effective_minor,currency,(runtime->>'applied_version')::bigint,runtime->'decision'->>'action' FROM ads_policy ORDER BY company_id,account_id,policy_id LIMIT 1001`)
	if err != nil {
		t.Fatal(err)
	}
	defer rows.Close()
	seen := 0
	for rows.Next() {
		var id, currency, action string
		var minor, version int64
		if err = rows.Scan(&id, &minor, &currency, &version, &action); err != nil {
			t.Fatal(err)
		}
		if expected[id] != minor || currency != "RUB" || version != expectedVersion || action != expectedAction {
			t.Fatal("company isolation/money/observe invariant failed")
		}
		seen++
	}
	if rows.Err() != nil || seen != len(expected) {
		t.Fatal("incomplete persisted sample")
	}
}
