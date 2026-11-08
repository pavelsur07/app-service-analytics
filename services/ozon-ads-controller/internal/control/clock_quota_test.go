package control

import (
	"context"
	"errors"
	"testing"
	"time"
)

type clockQuotaBackend struct{ taken, allowed, rejected int }

func (q *clockQuotaBackend) Take(context.Context, string, string, time.Time) error {
	q.taken++
	return nil
}
func (q *clockQuotaBackend) AuthorizationAllowed(context.Context, string, string, time.Time) error {
	q.allowed++
	return nil
}
func (q *clockQuotaBackend) RejectAuthorization(context.Context, string, string, time.Time) error {
	q.rejected++
	return nil
}
func TestClockQuotaCannotExpireBucketsOnWallStep(t *testing.T) {
	for _, step := range []time.Duration{time.Hour, -time.Hour} {
		at := time.Now()
		backend := &clockQuotaBackend{}
		guarded := GuardQuota(backend, func() time.Time { return at }, func() time.Time { return at })
		if guarded.Take(context.Background(), "company", "account", at) != nil {
			t.Fatal("normal reservation rejected")
		}
		if !errors.Is(guarded.Take(context.Background(), "company", "account", at.Add(step)), ErrClockSkew) {
			t.Fatal("clock step reached quota SQL")
		}
		if !errors.Is(guarded.Take(context.Background(), "company", "account", at), ErrClockSkew) || backend.taken != 1 {
			t.Fatal("clock step latch allowed HTTP quota")
		}
		if guarded.AuthorizationAllowed(context.Background(), "company", "account", at) != nil || guarded.RejectAuthorization(context.Background(), "company", "account", at) != nil || backend.allowed != 1 || backend.rejected != 1 {
			t.Fatal("authorization state not preserved")
		}
	}
}

func TestClockQuotaUsesInjectedMonotonicDeterministically(t *testing.T) {
	wall := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	mono := wall
	backend := &clockQuotaBackend{}
	guarded := GuardQuota(backend, func() time.Time { return wall }, func() time.Time { return mono })
	for range 3 {
		wall = wall.Add(time.Hour)
		mono = mono.Add(time.Hour)
		if guarded.Take(context.Background(), "company", "account", wall) != nil {
			t.Fatal("matching virtual clocks rejected")
		}
	}
	if backend.taken != 3 {
		t.Fatal("wrong reservations")
	}
}
