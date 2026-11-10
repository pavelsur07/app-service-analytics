package store

import (
	"context"
	"sync"
	"testing"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account/accounttest"
	"github.com/stretchr/testify/require"
)

func TestPerformanceCallAccountingIsAtomicAndSurvivesTaskRollback(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	at := time.Date(2060, 1, 2, 12, 0, 59, 0, time.UTC)
	var wg sync.WaitGroup
	for range 24 {
		wg.Go(func() {
			if err := tasks.RecordPerformanceCallAcrossConnections(ctx, accounttest.Ref("usage"), "token", at); err != nil {
				t.Error(err)
			}
		})
	}
	wg.Wait()
	var used int64
	require.NoError(t, tasks.pool.QueryRow(ctx, `SELECT attempts FROM performance_http_logical_count WHERE operation='token' AND bucket_start=$1`, at.Truncate(time.Minute)).Scan(&used))
	require.Equal(t, int64(24), used)
	tx, err := tasks.pool.Begin(ctx)
	require.NoError(t, err)
	require.NoError(t, tasks.RecordPerformanceCallAcrossConnections(ctx, accounttest.Ref("other"), "token", at))
	require.NoError(t, tx.Rollback(ctx))
	require.NoError(t, tasks.pool.QueryRow(ctx, `SELECT attempts FROM performance_http_logical_count WHERE operation='token' AND bucket_start=$1`, at.Truncate(time.Minute)).Scan(&used))
	require.Equal(t, int64(25), used)
	require.NoError(t, tasks.RecordPerformanceCallAcrossConnections(ctx, accounttest.Ref("usage"), "daily", at.Add(time.Second)))
	require.NoError(t, tasks.pool.QueryRow(ctx, `SELECT attempts FROM performance_http_logical_count WHERE operation='daily' AND bucket_start=$1`, at.Add(time.Second).Truncate(time.Minute)).Scan(&used))
	require.Equal(t, int64(1), used)
}
