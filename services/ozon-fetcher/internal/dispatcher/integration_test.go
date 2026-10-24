package dispatcher

import (
	"context"
	"fmt"
	"io"
	"log/slog"
	"sync"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account/accounttest"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/executor"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store/storetest"
)

// storeRunner завершает задачу в настоящей БД и следит, чтобы кабинет не вёлся дважды.
type storeRunner struct {
	tasks    *store.Tasks
	delay    map[string]time.Duration
	mu       sync.Mutex
	active   map[string]int
	violated []string
	finished int
}

func (r *storeRunner) Run(ctx context.Context, task store.Task, _ connections.Cabinet, _ string) executor.Result {
	r.mu.Lock()
	r.active[accounttest.Label(task.Account)]++
	if r.active[accounttest.Label(task.Account)] > 1 {
		r.violated = append(r.violated, accounttest.Label(task.Account))
	}
	r.mu.Unlock()

	select {
	case <-time.After(r.delay[accounttest.Label(task.Account)]):
	case <-ctx.Done():
	}

	r.mu.Lock()
	r.active[accounttest.Label(task.Account)]--
	r.mu.Unlock()
	out := store.Outcome{Status: store.StatusDone, Label: "done", ContentHash: fmt.Sprintf("%064d", 1)}
	if ctx.Err() != nil {
		out = store.Outcome{Status: store.StatusPending, Label: "released", KeepCursor: true, NextRunAt: time.Now()}
	}
	if _, err := r.tasks.FinishTask(context.WithoutCancel(ctx), task.TaskKey, task.AttemptID, out, time.Now()); err == nil && out.Status == store.StatusDone {
		r.mu.Lock()
		r.finished++
		r.mu.Unlock()
	}
	return executor.Result{Outcome: out.Label}
}

func TestTwoDispatchersOnOneDatabase(t *testing.T) {
	dsn := storetest.DSN(t, "dispatcher")
	ctx := context.Background()
	require.NoError(t, store.Migrate(ctx, dsn))
	pool, err := store.Open(ctx, dsn, store.PoolSize(4))
	require.NoError(t, err)
	t.Cleanup(pool.Close) // Cleanup — LIFO: пул закроется после остановки диспетчеров
	_, err = pool.Exec(ctx, `TRUNCATE raw_object, fetch_attempt, fetch_task, connection_state, connection_limit, resource_stop`)
	require.NoError(t, err)
	tasks := store.NewTasks(pool)

	refs := []string{"cab-a", "cab-b", "cab-slow"}
	var specs []store.TaskSpec
	for _, ref := range refs {
		for day := 1; day <= 4; day++ {
			specs = append(specs, store.TaskSpec{TaskKey: store.TaskKey{Account: accounttest.Ref(ref), ResourceType: "r", PeriodKey: fmt.Sprintf("2026-10-%02d", day)}, RunAt: time.Now().Add(-time.Minute)})
		}
	}
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, specs))

	runner := &storeRunner{tasks: tasks, active: map[string]int{}, delay: map[string]time.Duration{"cab-a": 5 * time.Millisecond, "cab-b": 5 * time.Millisecond, "cab-slow": 60 * time.Millisecond}}
	cabs := cabinets(refs...)
	logger := slog.New(slog.NewTextHandler(io.Discard, nil))
	cfg := Config{MaxParallel: 2, Lease: time.Minute, IdlePoll: 5 * time.Millisecond, ReleaseEvery: time.Second}

	runCtx, cancel := context.WithCancel(ctx)
	var wg sync.WaitGroup
	// Остановка — до закрытия пула (defer pool.Close выше выполнится позже) и при FailNow.
	stop := func() {
		cancel()
		wg.Wait()
	}
	t.Cleanup(stop)
	for i := range 2 {
		wg.Add(1)
		c := cfg
		c.Owner = fmt.Sprintf("test:%d", i)
		d := New(c, tasks, cabs, runner, logger, time.Now, nil)
		go func() {
			defer wg.Done()
			d.Run(runCtx)
		}()
	}

	require.Eventually(t, func() bool {
		runner.mu.Lock()
		defer runner.mu.Unlock()
		return runner.finished == 12
	}, 10*time.Second, 5*time.Millisecond)
	stop()

	assert.Empty(t, runner.violated, "кабинет ни разу не вёлся двумя воркерами")
	var notDone, running int
	require.NoError(t, pool.QueryRow(ctx, `SELECT count(*) FILTER (WHERE status <> 'done'), count(*) FILTER (WHERE status = 'running') FROM fetch_task`).Scan(&notDone, &running))
	assert.Zero(t, notDone)
	assert.Zero(t, running)
}
