package control

import (
	"context"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/ozon"
)

// GuardQuota checks the exact timestamp supplied to a quota reservation, before
// its SQL can expire buckets. This also guards HTTP inside an already running
// cycle. The initial host offset still requires external clock calibration.
func GuardQuota(quota ozon.Quota, now, monotonic func() time.Time) *ClockQuota {
	return &ClockQuota{quota: quota, gate: newClockGate(now(), monotonic()), monotonic: monotonic}
}

type ClockQuota struct {
	monotonic func() time.Time
	quota     ozon.Quota
	gate      *clockGate
}

func (q *ClockQuota) Check(at time.Time) bool { return q.gate.check(at, q.monotonic()) }
func (q *ClockQuota) Take(ctx context.Context, companyID, accountID string, at time.Time) error {
	if !q.Check(at) {
		return ErrClockSkew
	}
	return q.quota.Take(ctx, companyID, accountID, at)
}
func (q *ClockQuota) AuthorizationAllowed(ctx context.Context, companyID, accountID string, revision time.Time) error {
	return q.quota.AuthorizationAllowed(ctx, companyID, accountID, revision)
}
func (q *ClockQuota) RejectAuthorization(ctx context.Context, companyID, accountID string, revision time.Time) error {
	return q.quota.RejectAuthorization(ctx, companyID, accountID, revision)
}
