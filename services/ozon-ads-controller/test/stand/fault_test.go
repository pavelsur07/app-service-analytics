package stand

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/control"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/store"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/uuidv7"
)

func TestHTTPFailuresPersistAcrossRestartWithoutEarlyRetry(t *testing.T) {
	for _, status := range []int{401, 403, 429, 500} {
		t.Run(http.StatusText(status), func(t *testing.T) {
			db, _, dsn := database(t)
			at := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
			c, owners, _ := configuration(1, at)
			p := c.Policies[0]
			mock := &mock{owners: owners, statuses: map[string]int{p.AccountID: status}}
			httpServer := httptest.NewServer(http.HandlerFunc(mock.serve))
			t.Cleanup(httpServer.Close)
			now := func() time.Time { return at }
			reader, err := ozon.New(httpServer.Client(), httpServer.URL, db, now)
			if err != nil {
				t.Fatal(err)
			}
			engine := &control.Engine{Store: db, Reader: reader, Now: now}
			if err = engine.Install(context.Background(), c, at); err != nil {
				t.Fatal(err)
			}
			cycle(t, db, engine, at)
			initial := mock.calls.Load()
			// A fresh PHP snapshot wakes the scheduler, but cannot shorten the Ozon interval.
			at = at.Add(time.Second)
			for i := range c.Policies {
				c.Policies[i].PermissionUntil = at.Add(120 * time.Second)
			}
			if err = engine.Install(context.Background(), c, at); err != nil {
				t.Fatal(err)
			}
			cycle(t, db, engine, at)
			if mock.calls.Load() != initial {
				t.Fatal("early HTTP after configuration refresh")
			}
			db.Close()
			restarted, err := store.Open(context.Background(), dsn)
			if err != nil {
				t.Fatal(err)
			}
			t.Cleanup(restarted.Close)
			at = at.Add(600 * time.Second)
			for i := range c.Policies {
				c.Policies[i].PermissionUntil = at.Add(120 * time.Second)
			}
			mock.mu.Lock()
			mock.statuses[p.AccountID] = 0
			mock.mu.Unlock()
			nextReader, err := ozon.New(httpServer.Client(), httpServer.URL, restarted, now)
			if err != nil {
				t.Fatal(err)
			}
			nextEngine := &control.Engine{Store: restarted, Reader: nextReader, Now: now}
			if err = nextEngine.Install(context.Background(), c, at); err != nil {
				t.Fatal(err)
			}
			cycle(t, restarted, nextEngine, at)
			if status != 500 && mock.calls.Load() != initial {
				t.Fatal("restart bypassed rejection or Retry-After")
			}
			if status == 500 && mock.calls.Load() != initial+4 {
				t.Fatal("5xx did not recover on the next allowed poll")
			}
			result, err := restarted.Status(context.Background(), p.CompanyID, p.AccountID, p.ID)
			if err != nil || result.Decision.Action != "blocked" {
				t.Fatal("failure/recovery left observe boundary", err)
			}
			if status == 429 {
				if result.Error != "rate_limited" {
					t.Fatal("rate limit state lost")
				}
				// Only after Retry-After and a fresh grant may reads resume.
				at = at.Add(time.Hour)
				for i := range c.Policies {
					c.Policies[i].PermissionUntil = at.Add(120 * time.Second)
				}
				if err = nextEngine.Install(context.Background(), c, at); err != nil {
					t.Fatal(err)
				}
				cycle(t, restarted, nextEngine, at)
				if mock.calls.Load() != initial+4 {
					t.Fatal("Retry-After never recovered")
				}
			}
			t.Logf("FAULT status=%d restart_rejection_or_pause_preserved=true early_reads=0", status)
		})
	}
}
func cycle(t *testing.T, db *store.Store, engine *control.Engine, at time.Time) {
	t.Helper()
	claim, err := db.ClaimAccount(context.Background(), uuidv7.New(at), at)
	if err != nil || claim == nil {
		t.Fatal("no scheduled account", err)
	}
	if err = engine.Process(context.Background(), claim); err != nil {
		t.Fatal(err)
	}
}
func TestEmptyAndYesterdayAreNotZeroOrFresh(t *testing.T) {
	for _, empty := range []bool{true, false} {
		db, _, _ := database(t)
		at := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
		c, owners, _ := configuration(1, at)
		mock := &mock{owners: owners, statuses: map[string]int{}, empty: empty, yesterday: !empty}
		httpServer := httptest.NewServer(http.HandlerFunc(mock.serve))
		t.Cleanup(httpServer.Close)
		now := func() time.Time { return at }
		reader, err := ozon.New(httpServer.Client(), httpServer.URL, db, now)
		if err != nil {
			t.Fatal(err)
		}
		engine := &control.Engine{Store: db, Reader: reader, Now: now}
		if err = engine.Install(context.Background(), c, at); err != nil {
			t.Fatal(err)
		}
		cycle(t, db, engine, at)
		p := c.Policies[0]
		result, err := db.Status(context.Background(), p.CompanyID, p.AccountID, p.ID)
		if err != nil || result.Decision.Action != "blocked" || result.Decision.SourceAgeKnown {
			t.Fatal("invalid data became ready", err)
		}
		if empty && result.Effective != nil {
			t.Fatal("empty data fabricated zero")
		}
		if !empty && (result.Effective == nil || result.Effective.Minor != 103) {
			t.Fatal("failed SKU erased comparable aggregate")
		}
	}
}
func TestSlowAccountDoesNotHoldOtherAccount(t *testing.T) {
	db, _, _ := database(t)
	start := time.Now()
	base := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	now := func() time.Time { return base.Add(time.Since(start)) }
	c, owners, _ := configuration(2, now())
	slow := c.Policies[0].AccountID
	mock := &mock{owners: owners, statuses: map[string]int{}, slow: slow}
	httpServer := httptest.NewServer(http.HandlerFunc(mock.serve))
	t.Cleanup(httpServer.Close)
	reader, err := ozon.New(httpServer.Client(), httpServer.URL, db, now)
	if err != nil {
		t.Fatal(err)
	}
	engine := &control.Engine{Store: db, Reader: reader, Now: now}
	if err = engine.Install(context.Background(), c, now()); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	finished := make(chan string, 4)
	var group sync.WaitGroup
	group.Go(func() {
		if runErr := control.Workers(ctx, db, engine, 4, now, time.Now, uuidv7.New, func(claim *store.Claim, cycleErr error, _ time.Duration) {
			if ctx.Err() != nil {
				return
			}
			if cycleErr != nil || claim == nil {
				return
			}
			finished <- claim.AccountID
		}); runErr != nil {
			t.Error(runErr)
		}
	})
	t.Cleanup(func() { cancel(); group.Wait() })
	select {
	case owner := <-finished:
		if owner == slow {
			t.Fatal("slow account completed before healthy account")
		}
	case <-time.After(5 * time.Second):
		t.Fatal("healthy account blocked")
	}
	select {
	case owner := <-finished:
		if owner != slow {
			t.Fatal("duplicate account")
		}
	case <-time.After(12 * time.Second):
		t.Fatal("HTTP deadline failed")
	}
	cancel()
	group.Wait()
	pending, err := db.Pending(context.Background(), 100)
	if err != nil || len(pending) != 20 {
		t.Fatal("fault lost projections", err)
	}
	for _, item := range pending {
		if item.AccountID == slow && (item.Runtime.Effective != nil || item.Runtime.Error != "source_unavailable" || item.Runtime.Decision.Action != "blocked") {
			t.Fatal("hung account became healthy")
		}
	}
	t.Log("FAULT hung_account_timeout=true healthy_account_under_5s=true php_absent=true")
}

func TestDatabaseLossKeepsLeaseAndPollReservation(t *testing.T) {
	db, _, dsn := database(t)
	at := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	original := at
	c, owners, _ := configuration(1, at)
	mock := &mock{owners: owners, statuses: map[string]int{}}
	httpServer := httptest.NewServer(http.HandlerFunc(mock.serve))
	t.Cleanup(httpServer.Close)
	now := func() time.Time { return at }
	reader, err := ozon.New(httpServer.Client(), httpServer.URL, db, now)
	if err != nil {
		t.Fatal(err)
	}
	engine := &control.Engine{Store: db, Reader: reader, Now: now}
	if err = engine.Install(context.Background(), c, at); err != nil {
		t.Fatal(err)
	}
	claim, err := db.ClaimAccount(context.Background(), uuidv7.New(at), at)
	if err != nil || claim == nil {
		t.Fatal("claim failed", err)
	}
	db.Close()
	if err = engine.Process(context.Background(), claim); err == nil || mock.calls.Load() != 0 {
		t.Fatal("DB failure allowed unreserved HTTP")
	}
	restarted, err := store.Open(context.Background(), dsn)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(restarted.Close)
	before, err := restarted.ClaimAccount(context.Background(), uuidv7.New(at), at.Add(time.Second))
	if err != nil || before != nil {
		t.Fatal("restarting stole a live lease")
	}
	at = original.Add(91 * time.Second)
	for i := range c.Policies {
		c.Policies[i].PermissionUntil = at.Add(120 * time.Second)
	}
	nextReader, err := ozon.New(httpServer.Client(), httpServer.URL, restarted, now)
	if err != nil {
		t.Fatal(err)
	}
	nextEngine := &control.Engine{Store: restarted, Reader: nextReader, Now: now}
	if err = nextEngine.Install(context.Background(), c, at); err != nil {
		t.Fatal(err)
	}
	cycle(t, restarted, nextEngine, at)
	if mock.calls.Load() != 0 {
		t.Fatal("lost DB shortened the reserved 600s poll")
	}
	at = original.Add(601 * time.Second)
	for i := range c.Policies {
		c.Policies[i].PermissionUntil = at.Add(120 * time.Second)
	}
	if err = nextEngine.Install(context.Background(), c, at); err != nil {
		t.Fatal(err)
	}
	cycle(t, restarted, nextEngine, at)
	if mock.calls.Load() != 4 {
		t.Fatal("DB recovery did not resume the allowed poll")
	}
	t.Log("FAULT database_connection_loss_no_http=true lease_preserved=true poll_reservation_preserved=true recovery=true")
}

func TestClockStepInsideHTTPPreservesPersistedDayMoneyAndLatch(t *testing.T) {
	for _, cut := range []string{"aggregate", "sku"} {
		t.Run(cut, func(t *testing.T) {
			db, _, dsn := database(t)
			started := time.Now()
			base := time.Date(2026, 11, 8, 21, 10, 0, 0, time.UTC) // 00:10 Moscow, next reporting day
			var offset atomic.Int64
			var armed atomic.Bool
			now := func() time.Time { return base.Add(time.Since(started)).Add(time.Duration(offset.Load())) }
			c, owners, _ := configuration(1, now())
			for i := range c.Policies {
				c.Policies[i].DailyLimit.Minor = 100
			}
			mock := &mock{owners: owners, statuses: map[string]int{}, snapshotHook: func(stage string) {
				if stage == cut && armed.Swap(false) {
					offset.Add(-int64(time.Hour))
				}
			}}
			httpServer := httptest.NewServer(http.HandlerFunc(mock.serve))
			t.Cleanup(httpServer.Close)
			makeEngine := func(database *store.Store) *control.Engine {
				quota := control.GuardQuota(database, now, time.Now)
				reader, err := ozon.New(httpServer.Client(), httpServer.URL, quota, now)
				if err != nil {
					t.Fatal(err)
				}
				return &control.Engine{Store: database, Reader: reader, Now: now, ClockOK: quota.Check}
			}
			engine := makeEngine(db)
			if err := engine.Install(context.Background(), c, now()); err != nil {
				t.Fatal(err)
			}
			cycle(t, db, engine, now())
			p := c.Policies[0]
			before, err := db.Status(context.Background(), p.CompanyID, p.AccountID, p.ID)
			if err != nil || before.Effective == nil || before.Effective.Minor != 163 || !before.Latched || before.Day != "2026-11-09" {
				t.Fatal("initial protective state missing", err)
			}
			// Simulate a restart at the next permitted poll, then a wall step during HTTP.
			offset.Store(int64(601 * time.Second))
			for i := range c.Policies {
				c.Policies[i].PermissionUntil = now().Add(120 * time.Second)
			}
			engine = makeEngine(db)
			if err = engine.Install(context.Background(), c, now()); err != nil {
				t.Fatal(err)
			}
			claim, err := db.ClaimAccount(context.Background(), uuidv7.New(now()), now())
			if err != nil || claim == nil {
				t.Fatal("poll not due", err)
			}
			armed.Store(true)
			if err = engine.Process(context.Background(), claim); !errors.Is(err, control.ErrClockSkew) {
				t.Fatal("bad clock cycle did not abort", err)
			}
			unchanged, err := db.Status(context.Background(), p.CompanyID, p.AccountID, p.ID)
			if err != nil || unchanged.Effective == nil || unchanged.Effective.Minor != before.Effective.Minor || unchanged.Day != before.Day || unchanged.Latched != before.Latched || !unchanged.EvaluatedAt.Equal(before.EvaluatedAt) {
				t.Fatal("bad clock destroyed persisted money/day/latch", err)
			}
			db.Close()
			restored, err := store.Open(context.Background(), dsn)
			if err != nil {
				t.Fatal(err)
			}
			t.Cleanup(restored.Close)
			offset.Store(int64(693 * time.Second)) // expired old lease, corrected clock
			for i := range c.Policies {
				c.Policies[i].PermissionUntil = now().Add(120 * time.Second)
			}
			engine = makeEngine(restored)
			if err = engine.Install(context.Background(), c, now()); err != nil {
				t.Fatal(err)
			}
			count := mock.calls.Load()
			cycle(t, restored, engine, now())
			after, err := restored.Status(context.Background(), p.CompanyID, p.AccountID, p.ID)
			if err != nil || after.Effective == nil || after.Effective.Minor != before.Effective.Minor || after.Day != before.Day || !after.Latched || mock.calls.Load() != count {
				t.Fatal("corrected restart lost protection or performed early HTTP", err)
			}
		})
	}
}
