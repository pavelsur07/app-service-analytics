package control

import (
	"context"
	"errors"
	"sync/atomic"
	"testing"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/store"
)

func TestClockStepsLatchUntilRestart(t *testing.T) {
	base := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	for _, step := range []time.Duration{time.Hour, -time.Hour} {
		gate := newClockGate(base, base)
		if !gate.check(base.Add(time.Second), base.Add(time.Second)) {
			t.Fatal("normal elapsed time rejected")
		}
		if gate.check(base.Add(step+2*time.Second), base.Add(2*time.Second)) {
			t.Fatal("wall step accepted")
		}
		if gate.check(base.Add(3*time.Second), base.Add(3*time.Second)) {
			t.Fatal("skew latch cleared")
		}
	}
	fresh := newClockGate(base, base)
	if !fresh.check(base.Add(24*time.Hour), base.Add(24*time.Hour)) {
		t.Fatal("long normal elapsed time rejected")
	}
}
func TestWorkersRejectInvalidCapacityAndStop(t *testing.T) {
	for _, count := range []int{0, 33} {
		if Workers(context.Background(), nil, nil, count, time.Now, time.Now, func(time.Time) string { return "" }, nil) == nil {
			t.Fatal("invalid capacity accepted")
		}
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if err := Workers(ctx, nil, nil, 4, time.Now, time.Now, func(time.Time) string { return "" }, nil); err != nil {
		t.Fatal(err)
	}
}

type tickRepository struct {
	repository
	calls   atomic.Int64
	claimed chan struct{}
}

func (r *tickRepository) ClaimAccount(context.Context, string, time.Time) (*store.Claim, error) {
	r.calls.Add(1)
	select {
	case r.claimed <- struct{}{}:
	default:
	}
	return nil, nil
}
func TestWorkerClockStepStopsClaimsAndLatches(t *testing.T) {
	r := &tickRepository{claimed: make(chan struct{}, 10)}
	var offset atomic.Int64
	now := func() time.Time { return time.Now().Add(time.Duration(offset.Load())) }
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	skew := make(chan error, 10)
	go func() {
		done <- Workers(ctx, r, nil, 1, now, time.Now, func(time.Time) string { return "token" }, func(_ *store.Claim, err error, _ time.Duration) { skew <- err })
	}()
	t.Cleanup(func() {
		cancel()
		if err := <-done; err != nil {
			t.Error(err)
		}
	})
	select {
	case <-r.claimed:
	case <-time.After(3 * time.Second):
		t.Fatal("worker did not run")
	}
	offset.Store(int64(time.Hour))
	select {
	case err := <-skew:
		if !errors.Is(err, ErrClockSkew) {
			t.Fatal("wrong skew error")
		}
	case <-time.After(3 * time.Second):
		t.Fatal("clock step went unnoticed")
	}
	count := r.calls.Load()
	offset.Store(0)
	select {
	case err := <-skew:
		if !errors.Is(err, ErrClockSkew) {
			t.Fatal("clock latch cleared")
		}
	case <-time.After(3 * time.Second):
		t.Fatal("missing latched error")
	}
	if r.calls.Load() != count {
		t.Fatal("clock skew allowed more claims")
	}
}

func TestClockValidationBeforeFinishDoesNotPersistPartialRuntime(t *testing.T) {
	at := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	engine, claim, repo, _ := setup(at)
	checks := 0
	engine.ClockOK = func(time.Time) bool { checks++; return checks < 3 }
	if err := engine.Process(context.Background(), claim); !errors.Is(err, ErrClockSkew) {
		t.Fatal("last clock check did not abort", err)
	}
	if repo.items != nil {
		t.Fatal("untrusted cycle called Finish")
	}
}
