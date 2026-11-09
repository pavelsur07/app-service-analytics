package store

import (
	"context"
	"errors"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
)

// RecordPerformanceCallAcrossConnections records a technical aggregate in this
// service's own database. It stores no tenant data (CLAUDE.md §1). Reservation
// precedes the client call and survives task rollback/restart; not a wire quota.
func (s *Tasks) RecordPerformanceCallAcrossConnections(ctx context.Context, ref account.Ref, operation string, at time.Time) error {
	if !account.ValidID(ref.CompanyID) || !account.ValidID(ref.AccountID) {
		return errors.New("invalid_usage_scope")
	}
	switch operation {
	case "token", "campaigns", "expense", "daily", "report_order", "report_status", "report_download":
	default:
		return errors.New("invalid_usage_operation")
	}
	_, err := s.pool.Exec(ctx, `INSERT INTO performance_http_logical_count (bucket_start,operation,attempts) VALUES (date_trunc('minute',$1::timestamptz),$2,1) ON CONFLICT(bucket_start,operation) DO UPDATE SET attempts=performance_http_logical_count.attempts+1`, at, operation)
	return err
}
