package store

import (
	"context"
	"net/url"
	"os"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/observation"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/uuidv7"
)

func testStore(t *testing.T) (*Store, string) {
	t.Helper()
	dsn := os.Getenv("TEST_DB_DSN")
	if dsn == "" {
		t.Skip("TEST_DB_DSN unavailable")
	}
	ctx := context.Background()
	admin, err := pgx.Connect(ctx, dsn)
	if err != nil {
		t.Fatal(err)
	}
	schema := "ads_" + strings.ReplaceAll(uuidv7.New(time.Now()), "-", "")
	_, err = admin.Exec(ctx, "CREATE SCHEMA "+pgx.Identifier{schema}.Sanitize())
	if err != nil {
		t.Fatal(err)
	}
	u, err := url.Parse(dsn)
	if err != nil {
		t.Fatal(err)
	}
	q := u.Query()
	q.Set("search_path", schema)
	u.RawQuery = q.Encode()
	isolated := u.String()
	if err = Migrate(ctx, isolated, false); err != nil {
		t.Fatal(err)
	}
	s, err := Open(ctx, isolated)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		s.Close()
		_, dropErr := admin.Exec(context.Background(), "DROP SCHEMA "+pgx.Identifier{schema}.Sanitize()+" CASCADE")
		if dropErr != nil {
			t.Error(dropErr)
		}
		if closeErr := admin.Close(context.Background()); closeErr != nil {
			t.Error(closeErr)
		}
	})
	return s, isolated
}
func policy(now time.Time) observation.Policy {
	return observation.Policy{CompanyID: uuidv7.New(now), AccountID: uuidv7.New(now), ID: uuidv7.New(now), CampaignID: "49761882", Version: 1, Mode: "observe", Enabled: true, DailyLimit: observation.Money{Minor: 10000, Currency: "RUB"}, Timezone: "Europe/Moscow", Start: "09:00", Stop: "21:00", PollSeconds: 600, PermissionUntil: now.Add(120 * time.Second)}
}
func TestMigrationRoundTrip(t *testing.T) {
	s, dsn := testStore(t)
	ctx := context.Background()
	if err := s.Ready(ctx); err != nil {
		t.Fatal(err)
	}
	if pending, err := MigrationsPending(ctx, dsn); err != nil || pending {
		t.Fatal(pending, err)
	}
	if err := Migrate(ctx, dsn, true); err != nil {
		t.Fatal(err)
	}
	if pending, err := MigrationsPending(ctx, dsn); err != nil || !pending {
		t.Fatal(pending, err)
	}
	if err := Migrate(ctx, dsn, false); err != nil {
		t.Fatal(err)
	}
}
func TestVersionScopeFencingAndRestart(t *testing.T) {
	s, _ := testStore(t)
	ctx := context.Background()
	now := time.Now().UTC().Truncate(time.Second)
	p := policy(now)
	if err := s.Apply(ctx, []observation.Policy{p}, now); err != nil {
		t.Fatal(err)
	}
	claim, err := s.ClaimAccount(ctx, uuidv7.New(now), now)
	if err != nil || claim == nil {
		t.Fatalf("claim %v %v", claim, err)
	}
	if again, claimErr := s.ClaimAccount(ctx, uuidv7.New(now), now); claimErr != nil || again != nil {
		t.Fatal("lease duplicated", claimErr)
	}
	claim.Items[0].Runtime = Runtime{Day: now.Format(time.DateOnly), Effective: &observation.Money{Minor: 1234, Currency: "RUB"}, AppliedVersion: 1, EvaluatedAt: now, Decision: observation.Decision{Action: "blocked", Reason: "reserve_unavailable"}}
	if err = s.Finish(ctx, p.CompanyID, p.AccountID, claim.Token, claim.Items, now.Add(600*time.Second), now.Add(time.Minute), now); err != nil {
		t.Fatal(err)
	}
	if err = s.Finish(ctx, p.CompanyID, p.AccountID, claim.Token, claim.Items, now, now, now); err == nil {
		t.Fatal("old lease accepted")
	}
	if _, err = s.Status(ctx, uuidv7.New(now), p.AccountID, p.ID); err == nil {
		t.Fatal("cross company read")
	}
	runtime, err := s.Status(ctx, p.CompanyID, p.AccountID, p.ID)
	if err != nil || runtime.Effective.Minor != 1234 {
		t.Fatal(runtime, err)
	}
	pending, err := s.Pending(ctx, 100)
	if err != nil || len(pending) != 1 {
		t.Fatal(pending, err)
	}
	if err = s.Published(ctx, p.CompanyID, p.AccountID, p.ID, now); err != nil {
		t.Fatal(err)
	}
	pending, err = s.Pending(ctx, 100)
	if err != nil || len(pending) != 0 {
		t.Fatal(pending, err)
	}
	p.Version = 2
	p.DailyLimit.Minor = 1200
	if err = s.Apply(ctx, []observation.Policy{p}, now); err != nil {
		t.Fatal(err)
	}
	p.Version = 1
	if err = s.Apply(ctx, []observation.Policy{p}, now); err == nil {
		t.Fatal("old version accepted")
	}
	p.Version = 2
	p.DailyLimit.Minor = 1100
	if err = s.Apply(ctx, []observation.Policy{p}, now); err == nil {
		t.Fatal("same version changed")
	}
	runtime, err = s.Status(ctx, p.CompanyID, p.AccountID, p.ID)
	if err != nil || runtime.Effective.Minor != 1234 {
		t.Fatal("history reset", err)
	}
	if err = s.Apply(ctx, nil, now); err != nil {
		t.Fatal(err)
	}
	claim, err = s.ClaimAccount(ctx, uuidv7.New(now), now.Add(time.Minute))
	if err != nil || claim == nil || claim.Items[0].Policy.PermissionUntil.After(now) {
		t.Fatal("revocation", claim, err)
	}
}
func TestConcurrentQuotaDoesNotOverspend(t *testing.T) {
	s, _ := testStore(t)
	ctx := context.Background()
	now := time.Now().UTC()
	p := policy(now)
	if _, err := s.pool.Exec(ctx, `INSERT INTO ads_http_quota(scope,window_start,used)VALUES('service',$1,69999)`, now); err != nil {
		t.Fatal(err)
	}
	var mu sync.Mutex
	success := 0
	var wg sync.WaitGroup
	for range 10 {
		wg.Go(func() {
			if s.Take(ctx, p.CompanyID, p.AccountID, now) == nil {
				mu.Lock()
				success++
				mu.Unlock()
			}
		})
	}
	wg.Wait()
	if success != 1 {
		t.Fatal(success)
	}
	if err := s.Take(ctx, p.CompanyID, p.AccountID, now.Add(24*time.Hour+2*time.Minute)); err != nil {
		t.Fatal(err)
	}
	if err := s.Take(ctx, "other", p.AccountID, now); err == nil {
		t.Fatal("bad scope")
	}
}

func TestVersionChangeRetainsObservedMaximumWithoutAcknowledgingNewVersion(t *testing.T) {
	s, _ := testStore(t)
	ctx := context.Background()
	now := time.Now().UTC().Truncate(time.Second)
	p := policy(now)
	if err := s.Apply(ctx, []observation.Policy{p}, now); err != nil {
		t.Fatal(err)
	}
	claim, err := s.ClaimAccount(ctx, uuidv7.New(now), now)
	if err != nil || claim == nil {
		t.Fatal(err)
	}
	p.Version = 2
	p.DailyLimit.Minor = 120
	if err = s.Apply(ctx, []observation.Policy{p}, now); err != nil {
		t.Fatal(err)
	}
	claim.Items[0].Runtime = Runtime{Day: now.Format(time.DateOnly), Effective: &observation.Money{Minor: 150, Currency: "RUB"}, AppliedVersion: 1, EvaluatedAt: now}
	if err = s.Finish(ctx, p.CompanyID, p.AccountID, claim.Token, claim.Items, now.Add(600*time.Second), now.Add(time.Minute), now); err != nil {
		t.Fatal(err)
	}
	fresh, err := s.ClaimAccount(ctx, uuidv7.New(now), now)
	if err != nil || fresh == nil {
		t.Fatal("new config not woken", err)
	}
	if fresh.Items[0].Policy.Version != 2 || fresh.Items[0].Runtime.Effective.Minor != 150 || fresh.Items[0].Runtime.AppliedVersion != 1 || !fresh.NextPoll.After(now) {
		t.Fatalf("%+v", fresh)
	}
}
func TestRejectedCredentialRevisionSurvivesRestartAndLateFailures(t *testing.T) {
	s, dsn := testStore(t)
	ctx := context.Background()
	now := time.Now().UTC()
	p := policy(now)
	if err := s.AuthorizationAllowed(ctx, p.CompanyID, p.AccountID, now); err != nil {
		t.Fatal(err)
	}
	if err := s.RejectAuthorization(ctx, p.CompanyID, p.AccountID, now); err != nil {
		t.Fatal(err)
	}
	fresh, err := Open(ctx, dsn)
	if err != nil {
		t.Fatal(err)
	}
	defer fresh.Close()
	if err = fresh.AuthorizationAllowed(ctx, p.CompanyID, p.AccountID, now); err == nil {
		t.Fatal("rejection lost on restart")
	}
	if err = fresh.AuthorizationAllowed(ctx, p.CompanyID, p.AccountID, now.Add(time.Second)); err != nil {
		t.Fatal("new revision blocked", err)
	}
	if err = fresh.RejectAuthorization(ctx, p.CompanyID, p.AccountID, now.Add(-time.Second)); err != nil {
		t.Fatal(err)
	}
	if err = fresh.AuthorizationAllowed(ctx, p.CompanyID, p.AccountID, now.Add(time.Second)); err != nil {
		t.Fatal("late old rejection blocked new key", err)
	}
}

func TestChangedCadenceReconcilesFromLastReservationAndKeepsRetryAfter(t *testing.T) {
	s, _ := testStore(t)
	ctx := context.Background()
	now := time.Now().UTC().Truncate(time.Second)
	p := policy(now)
	p.PollSeconds = 86400
	if err := s.Apply(ctx, []observation.Policy{p}, now); err != nil {
		t.Fatal(err)
	}
	claim, err := s.ClaimAccount(ctx, uuidv7.New(now), now)
	if err != nil || claim == nil {
		t.Fatal(err)
	}
	if err = s.Finish(ctx, p.CompanyID, p.AccountID, claim.Token, claim.Items, now.Add(24*time.Hour), now.Add(24*time.Hour), now); err != nil {
		t.Fatal(err)
	}
	p.Version = 2
	p.PollSeconds = 600
	if err = s.Apply(ctx, []observation.Policy{p}, now); err != nil {
		t.Fatal(err)
	}
	var due time.Time
	if err = s.pool.QueryRow(ctx, `SELECT next_poll FROM ads_account WHERE company_id=$1 AND account_id=$2`, p.CompanyID, p.AccountID).Scan(&due); err != nil || !due.Equal(now.Add(600*time.Second)) {
		t.Fatal("shorter cadence not applied", due, err)
	}
	// A 429 cooldown is independent of a subsequent settings refresh.
	claim, err = s.ClaimAccount(ctx, uuidv7.New(now), now.Add(600*time.Second))
	if err != nil || claim == nil {
		t.Fatal(err)
	}
	claim.Items[0].Runtime.Error = "rate_limited"
	pause := now.Add(2 * time.Hour)
	if err = s.Finish(ctx, p.CompanyID, p.AccountID, claim.Token, claim.Items, pause, pause, now.Add(600*time.Second)); err != nil {
		t.Fatal(err)
	}
	p.Version = 3
	p.PermissionUntil = now.Add(720 * time.Second)
	if err = s.Apply(ctx, []observation.Policy{p}, now.Add(600*time.Second)); err != nil {
		t.Fatal(err)
	}
	if err = s.pool.QueryRow(ctx, `SELECT next_poll FROM ads_account WHERE company_id=$1 AND account_id=$2`, p.CompanyID, p.AccountID).Scan(&due); err != nil || !due.Equal(pause) {
		t.Fatal("refresh shortened Retry-After", due, err)
	}
}
