package control

import (
	"context"
	"errors"
	"sync"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/store"
)

type Processor interface {
	Process(context.Context, *store.Claim) error
}

// Workers is the production account loop, also exercised by the acceptance stand.
// It never creates an unbounded queue: each worker claims at most one account/tick.
func Workers(ctx context.Context, repository Repository, processor Processor, count int, now, monotonic func() time.Time, newToken func(time.Time) string, report func(*store.Claim, error, time.Duration)) error {
	if count < 1 || count > 32 {
		return errors.New("invalid_workers")
	}
	gate := newClockGate(now(), monotonic())
	var group sync.WaitGroup
	for range count {
		group.Go(func() {
			ticker := time.NewTicker(time.Second)
			defer ticker.Stop()
			for {
				select {
				case <-ctx.Done():
					return
				case <-ticker.C:
				}
				started := monotonic()
				job, stop := context.WithTimeout(ctx, 45*time.Second)
				at := now()
				if !gate.check(at, monotonic()) {
					stop()
					if report != nil {
						report(nil, ErrClockSkew, monotonic().Sub(started))
					}
					continue
				}
				claim, err := repository.ClaimAccount(job, newToken(at), at)
				if err == nil && claim != nil {
					err = processor.Process(job, claim)
				}
				stop()
				if report != nil && (claim != nil || err != nil) {
					report(claim, err, monotonic().Sub(started))
				}
			}
		})
	}
	group.Wait()
	return nil
}
