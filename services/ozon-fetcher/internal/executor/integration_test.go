package executor

import (
	"context"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"os"
	"sync/atomic"
	"testing"
	"time"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/s3"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account/accounttest"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/rawstore"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store/storetest"
)

// Интеграция: настоящие postgres (TEST_DB_DSN) и S3 (TEST_S3_ENDPOINT,
// TEST_S3_BUCKET), Ozon — httptest. Без переменных — пропуск.
func TestByDayFailureMidPaginationResumesWithoutDuplicates(t *testing.T) {
	endpoint, bucket := os.Getenv("TEST_S3_ENDPOINT"), os.Getenv("TEST_S3_BUCKET")
	if endpoint == "" || bucket == "" {
		t.Skip("TEST_S3_ENDPOINT / TEST_S3_BUCKET are not set")
	}
	dsn := storetest.DSN(t, "executor")
	ctx := context.Background()
	require.NoError(t, store.Migrate(ctx, dsn))
	pool, err := store.Open(ctx, dsn, store.PoolSize(4))
	require.NoError(t, err)
	defer pool.Close()
	_, err = pool.Exec(ctx, `TRUNCATE raw_object, fetch_attempt, fetch_task, connection_state, connection_limit, resource_stop`)
	require.NoError(t, err)
	tasks := store.NewTasks(pool)

	s3client := rawstore.NewS3Client(endpoint, "us-east-1", "any", "any")
	_, _ = s3client.CreateBucket(ctx, &s3.CreateBucketInput{Bucket: aws.String(bucket)})
	raw := rawstore.New(s3client, bucket, 2)

	bodies := map[string]string{"": `{"accruals":[{"accrual_id":"1"}],"last_id":"c1"}`, "c1": `{"accruals":[{"accrual_id":"2"}],"last_id":"c2"}`, "c2": `{"accruals":[],"last_id":""}`}
	var page2Calls atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var req struct {
			LastID string `json:"last_id"`
		}
		b, _ := io.ReadAll(r.Body)
		_ = jsonUnmarshal(b, &req)
		if req.LastID == "c1" && page2Calls.Add(1) == 1 {
			w.WriteHeader(http.StatusBadGateway) // первая попытка падает на странице 2
			return
		}
		_, _ = io.WriteString(w, bodies[req.LastID])
	}))
	defer srv.Close()
	client := ozon.NewClient(srv.URL, srv.Client(), slog.New(slog.NewTextHandler(io.Discard, nil)), time.Now)

	now := time.Date(2026, 10, 17, 9, 0, 0, 0, time.UTC)
	exec := New(DefaultConfig(), tasks, raw, client, &fakeRefresher{}, slog.New(slog.NewTextHandler(testWriter{t}, nil)),
		func() time.Time { return now }, func(time.Duration) time.Duration { return 0 }, ozon.SleepContext)
	cab := connections.Cabinet{Account: accounttest.Ref("ref-int"), Seller: &ozon.Credentials{ClientID: "c", APIKey: "k"}}
	key := store.TaskKey{Account: accounttest.Ref("ref-int"), ResourceType: ResourceAccrualByDay, PeriodKey: "2026-10-15"}
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []store.TaskSpec{{TaskKey: key, RunAt: now}}))

	// Попытка 1: страница 1 сохранена, страница 2 — 502.
	first, err := tasks.ClaimNextAcrossConnections(ctx, accounttest.Refs("ref-int"), "w", "00000000-0000-7000-8000-000000000001", "trace", now, time.Minute)
	require.NoError(t, err)
	assert.Equal(t, "transient", exec.Run(ctx, first, cab, "trace").Outcome)

	// Попытка 2 — после backoff, с курсора.
	now = now.Add(2 * time.Minute)
	second, err := tasks.ClaimNextAcrossConnections(ctx, accounttest.Refs("ref-int"), "w", "00000000-0000-7000-8000-000000000002", "trace", now, time.Minute)
	require.NoError(t, err)
	assert.Equal(t, "c1", second.Cursor)
	assert.Equal(t, 2, second.NextPageNo)
	assert.Equal(t, "done", exec.Run(ctx, second, cab, "trace").Outcome)

	rows, err := pool.Query(ctx, `SELECT r.page_no, r.sha256 FROM raw_object r ORDER BY r.page_no`)
	require.NoError(t, err)
	var pageNos []int
	var hashes []string
	for rows.Next() {
		var no int
		var h string
		require.NoError(t, rows.Scan(&no, &h))
		pageNos, hashes = append(pageNos, no), append(hashes, h)
	}
	require.NoError(t, rows.Err())
	assert.Equal(t, []int{1, 2, 3}, pageNos, "каждая страница — ровно один раз")

	var status, contentHashDB string
	require.NoError(t, pool.QueryRow(ctx, `SELECT status, content_hash FROM fetch_task`).Scan(&status, &contentHashDB))
	assert.Equal(t, store.StatusDone, status)
	assert.Equal(t, contentHash([]string{sha(bodies[""]), sha(bodies["c1"]), sha(bodies["c2"])}), contentHashDB, "хеш как у загрузки за раз")
	assert.Equal(t, hashes, []string{sha(bodies[""]), sha(bodies["c1"]), sha(bodies["c2"])})
}
