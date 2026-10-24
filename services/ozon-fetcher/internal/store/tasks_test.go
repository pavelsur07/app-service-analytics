package store

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account/accounttest"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store/storetest"
)

// Интеграционные тесты: TEST_DB_DSN — отдельная пустая БД; каждый тест
// начинает с чистых таблиц. Без переменной — пропуск.
func testTasks(t *testing.T) *Tasks {
	t.Helper()
	dsn := storetest.DSN(t, "store")
	ctx := context.Background()
	require.NoError(t, Migrate(ctx, dsn))
	pool, err := Open(ctx, dsn, PoolSize(4))
	require.NoError(t, err)
	t.Cleanup(pool.Close)
	_, err = pool.Exec(ctx, `TRUNCATE raw_object, fetch_attempt, fetch_task, connection_state, connection_limit, resource_stop, planner_run`)
	require.NoError(t, err)
	return NewTasks(pool)
}

var t0 = time.Date(2026, 10, 17, 9, 0, 0, 0, time.UTC)

func spec(ref, resource, period string, priority int) TaskSpec {
	return TaskSpec{TaskKey: TaskKey{Account: accounttest.Ref(ref), ResourceType: resource, PeriodKey: period}, Priority: priority, RunAt: t0}
}

var attemptSeq atomic.Int64

func newAttemptID() string {
	return fmt.Sprintf("00000000-0000-7000-8000-%012d", attemptSeq.Add(1))
}

func claim(t *testing.T, tasks *Tasks, refs []account.Ref, now time.Time) (Task, error) {
	t.Helper()
	return tasks.ClaimNextAcrossConnections(context.Background(), refs, "worker-1", newAttemptID(), "trace-1", now, time.Minute)
}

func status(t *testing.T, tasks *Tasks, key TaskKey) string {
	t.Helper()
	var s string
	require.NoError(t, tasks.pool.QueryRow(context.Background(),
		`SELECT status FROM fetch_task WHERE company_id=$1 AND marketplace_account_id=$2 AND resource_type=$3 AND period_key=$4`,
		key.Account.CompanyID, key.Account.AccountID, key.ResourceType, key.PeriodKey).Scan(&s))
	return s
}

func TestUpsertIsIdempotentAndKeepsRunning(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	s := spec("ref-a", "ozon.finance.accrual-by-day", "2026-10-15", 1)

	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s, s}))
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
	var n int
	require.NoError(t, tasks.pool.QueryRow(ctx, `SELECT count(*) FROM fetch_task`).Scan(&n))
	assert.Equal(t, 1, n)

	task, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	s.Refresh = true
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
	assert.Equal(t, StatusRunning, status(t, tasks, task.TaskKey), "upsert не сбрасывает running")
}

func TestUpsertRefreshReopensDoneOnlyOnRequest(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	s := spec("ref-a", "ozon.finance.accrual-by-day", "2026-10-15", 0)
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
	task, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("a")}, t0)
	require.NoError(t, err)

	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
	assert.Equal(t, StatusDone, status(t, tasks, s.TaskKey))

	s.Refresh = true
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
	assert.Equal(t, StatusPending, status(t, tasks, s.TaskKey))
}

func TestUpsertRefreshRevivesPermanentFailure(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	s := spec("ref-a", "r", "2026-10-01", 0)
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
	task, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusFailedPermanent, Label: "invalid_response", ErrorClass: "invalid_response", NextRunAt: t0}, t0)
	require.NoError(t, err)

	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
	assert.Equal(t, StatusFailedPermanent, status(t, tasks, s.TaskKey), "обычная постановка не перезапускает")
	s.Refresh = true
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
	assert.Equal(t, StatusPending, status(t, tasks, s.TaskKey))
}

func TestConcurrentClaimsNeverRunOneConnectionTwice(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	var specs []TaskSpec
	for day := 1; day <= 20; day++ {
		specs = append(specs, spec("ref-a", "ozon.finance.accrual-by-day", fmt.Sprintf("2026-10-%02d", day), 0))
	}
	specs = append(specs, spec("ref-b", "ozon.finance.accrual-by-day", "2026-10-01", 0))
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, specs))

	var wg sync.WaitGroup
	var mu sync.Mutex
	claimedByRef := map[string]int{}
	for range 16 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			task, err := tasks.ClaimNextAcrossConnections(ctx, accounttest.Refs("ref-a", "ref-b"), "w", newAttemptID(), "trace", t0, time.Minute)
			if errors.Is(err, ErrNoTask) {
				return
			}
			assert.NoError(t, err)
			mu.Lock()
			claimedByRef[accounttest.Label(task.Account)]++
			mu.Unlock()
		}()
	}
	wg.Wait()

	assert.Equal(t, map[string]int{"ref-a": 1, "ref-b": 1}, claimedByRef)
	var running int
	require.NoError(t, tasks.pool.QueryRow(ctx, `SELECT count(*) FROM fetch_task WHERE status='running'`).Scan(&running))
	assert.Equal(t, 2, running)
}

func TestClaimOrderPriorityThenLeastRecentlyServed(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{
		spec("ref-a", "r", "2026-10-01", 0),
		spec("ref-b", "r", "2026-10-01", 0),
		spec("ref-c", "r", "2026-10-01", 5),
	}))
	a, b := accounttest.Ref("ref-a"), accounttest.Ref("ref-b")
	_, err := tasks.pool.Exec(ctx, `INSERT INTO connection_state (company_id, marketplace_account_id, last_served_at) VALUES ($1, $2, $3), ($4, $5, $6)`,
		a.CompanyID, a.AccountID, t0.Add(-time.Hour), b.CompanyID, b.AccountID, t0.Add(-2*time.Hour))
	require.NoError(t, err)
	refs := accounttest.Refs("ref-a", "ref-b", "ref-c")

	first, err := claim(t, tasks, refs, t0)
	require.NoError(t, err)
	second, err := claim(t, tasks, refs, t0)
	require.NoError(t, err)
	third, err := claim(t, tasks, refs, t0)
	require.NoError(t, err)

	assert.Equal(t, []string{"ref-c", "ref-b", "ref-a"}, []string{accounttest.Label(first.Account), accounttest.Label(second.Account), accounttest.Label(third.Account)})
}

func TestClaimSkipsInactiveSuspendedStoppedAndFuture(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	future := spec("ref-f", "r", "2026-10-01", 0)
	future.RunAt = t0.Add(time.Hour)
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{
		spec("ref-gone", "r", "2026-10-01", 0),
		spec("ref-s", "r", "2026-10-01", 0),
		spec("ref-x", "stopped", "2026-10-01", 0),
		future,
	}))
	require.NoError(t, tasks.SuspendConnection(ctx, accounttest.Ref("ref-s"), "auth", t0.Add(time.Hour)))
	require.NoError(t, tasks.StopResourceAllConnections(ctx, "stopped", "obsolete_endpoint"))

	_, err := claim(t, tasks, accounttest.Refs("ref-s", "ref-x", "ref-f"), t0)
	assert.ErrorIs(t, err, ErrNoTask)

	task, err := claim(t, tasks, accounttest.Refs("ref-s"), t0.Add(2*time.Hour))
	require.NoError(t, err, "пауза истекла")
	assert.Equal(t, accounttest.Ref("ref-s"), task.Account)
}

func TestExpiredLeaseIsReleasedWithCursor(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{spec("ref-a", "r", "2026-10-01", 0)}))
	task, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	require.NoError(t, tasks.RecordPage(ctx, task.TaskKey, task.AttemptID, rawObj(1), "cursor-1"))

	released, err := tasks.ReleaseExpiredAcrossConnections(ctx, t0.Add(30*time.Second))
	require.NoError(t, err)
	assert.Zero(t, released, "lease ещё жив")

	released, err = tasks.ReleaseExpiredAcrossConnections(ctx, t0.Add(2*time.Minute))
	require.NoError(t, err)
	assert.EqualValues(t, 1, released)

	next, err := claim(t, tasks, accounttest.Refs("ref-a"), t0.Add(2*time.Minute))
	require.NoError(t, err)
	assert.Equal(t, "cursor-1", next.Cursor)
	assert.Equal(t, 2, next.NextPageNo)
	assert.Equal(t, []string{hash("p")}, next.PageHashes, "хеши страниц прежней попытки сохранены")
	assert.Equal(t, "lease_expired", next.LastErrorClass)
	assert.Equal(t, 2, next.Attempts)

	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusDone}, t0)
	assert.ErrorIs(t, err, ErrLeaseLost, "старая попытка не может закрыть перехваченную задачу")
	assert.ErrorIs(t, tasks.RecordPage(ctx, task.TaskKey, task.AttemptID, rawObj(2), ""), ErrLeaseLost)
	assert.ErrorIs(t, tasks.ExtendLease(ctx, task.TaskKey, task.AttemptID, t0.Add(time.Hour)), ErrLeaseLost)
}

func TestReleaseRacingWithLeaseExtensionKeepsTaskAndAttemptConsistent(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	for i := range 40 {
		key := spec(fmt.Sprintf("ref-%d", i), "r", "2026-10-01", 0)
		require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{key}))
		task, err := claim(t, tasks, []account.Ref{key.Account}, t0)
		require.NoError(t, err)

		var wg sync.WaitGroup
		wg.Add(2)
		go func() {
			defer wg.Done()
			_ = tasks.ExtendLease(ctx, task.TaskKey, task.AttemptID, t0.Add(time.Hour))
		}()
		go func() {
			defer wg.Done()
			_, err := tasks.ReleaseExpiredAcrossConnections(ctx, t0.Add(2*time.Minute))
			assert.NoError(t, err)
		}()
		wg.Wait()

		var taskStatus string
		var outcome *string
		require.NoError(t, tasks.pool.QueryRow(ctx, `
			SELECT t.status, a.outcome FROM fetch_task t JOIN fetch_attempt a ON a.attempt_id = $5
			WHERE t.company_id = $1 AND t.marketplace_account_id = $2 AND t.resource_type = $3 AND t.period_key = $4`,
			task.Account.CompanyID, task.Account.AccountID, task.ResourceType, task.PeriodKey, task.AttemptID).Scan(&taskStatus, &outcome))
		if taskStatus == StatusRunning {
			assert.Nil(t, outcome, "задача продлена — попытка не закрыта")
		} else {
			require.NotNil(t, outcome)
			assert.Equal(t, "lease_expired", *outcome, "задача освобождена — попытка закрыта")
		}
	}
}

func TestFinishTaskRevisionFollowsContentHash(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	s := spec("ref-a", "r", "2026-10-01", 0)
	s.Refresh = true
	run := func(h string) int {
		require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
		task, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
		require.NoError(t, err)
		rev, err := tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: h}, t0)
		require.NoError(t, err)
		return rev
	}

	assert.Equal(t, 1, run(hash("a")))
	assert.Equal(t, 1, run(hash("a")), "тот же контент — та же ревизия")
	assert.Equal(t, 2, run(hash("b")))
}

func TestFinishTaskNotDoneKeepsOrResetsCursor(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{spec("ref-a", "r", "2026-10-01", 0)}))
	task, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	require.NoError(t, tasks.RecordPage(ctx, task.TaskKey, task.AttemptID, rawObj(1), "cursor-1"))
	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusFailedRetryable, Label: "transient", ErrorClass: "transient", NextRunAt: t0, KeepCursor: true}, t0)
	require.NoError(t, err)

	again, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	assert.Equal(t, "cursor-1", again.Cursor)

	_, err = tasks.FinishTask(ctx, again.TaskKey, again.AttemptID, Outcome{Status: StatusFailedRetryable, Label: "invalid_response", ErrorClass: "invalid_response", NextRunAt: t0}, t0)
	require.NoError(t, err)
	third, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	assert.Empty(t, third.Cursor, "без KeepCursor загрузка дня начинается заново")
	assert.Equal(t, 1, third.NextPageNo)
	assert.Empty(t, third.PageHashes)
}

func TestLimitsRoundTrip(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	require.NoError(t, tasks.SaveCurrentRate(ctx, accounttest.Ref("ref-a"), "/v1/x", 0.5))
	require.NoError(t, tasks.SetOverride(ctx, accounttest.Ref("ref-a"), "/v1/x", 2))
	require.NoError(t, tasks.SaveCurrentRate(ctx, accounttest.Ref("ref-a"), "/v1/x", 0.25))
	require.NoError(t, tasks.SetOverride(ctx, accounttest.Ref("ref-b"), "/v1/x", 0))

	limits, err := tasks.LoadLimitsAllConnections(ctx)
	require.NoError(t, err)
	assert.ElementsMatch(t, []Limit{
		{Account: accounttest.Ref("ref-a"), Endpoint: "/v1/x", Override: 2, Current: 0.25},
		{Account: accounttest.Ref("ref-b"), Endpoint: "/v1/x"},
	}, limits)
}

func hash(s string) string {
	return fmt.Sprintf("%064s", s)
}

func rawObj(page int) RawObject {
	return RawObject{PageNo: page, StorageURI: fmt.Sprintf("s3://bucket/p%d", page), SHA256: hash("p"), SizeBytes: 10, StoredBytes: 5, ContentType: "application/json"}
}

func TestPlanOnceRunsOncePerDateEvenConcurrently(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	s := spec("ref-a", "r", "2026-10-01", 5)
	s.Refresh = true
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{{TaskKey: s.TaskKey, RunAt: t0}}))
	task, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("a")}, t0)
	require.NoError(t, err)

	var wg sync.WaitGroup
	var ran atomic.Int32
	for range 8 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			done, err := tasks.PlanOnce(ctx, accounttest.Ref("ref-a"), "tail", "2026-10-17", []TaskSpec{s})
			assert.NoError(t, err)
			if done {
				ran.Add(1)
			}
		}()
	}
	wg.Wait()
	assert.EqualValues(t, 1, ran.Load(), "проход за дату — ровно один")
	assert.Equal(t, StatusPending, status(t, tasks, s.TaskKey))

	// Задача снова загружена — тот же проход за ту же дату её не трогает.
	task, err = claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("a")}, t0)
	require.NoError(t, err)
	done, err := tasks.PlanOnce(ctx, accounttest.Ref("ref-a"), "tail", "2026-10-17", []TaskSpec{s})
	require.NoError(t, err)
	assert.False(t, done)
	assert.Equal(t, StatusDone, status(t, tasks, s.TaskKey))

	done, err = tasks.PlanOnce(ctx, accounttest.Ref("ref-a"), "tail", "2026-10-18", []TaskSpec{s})
	require.NoError(t, err)
	assert.True(t, done, "следующая дата — новый проход")
}

func TestAuthFailuresAreCountedPerCabinet(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	pause := func(n int) time.Duration {
		if n >= 2 {
			return time.Hour
		}
		return time.Minute
	}
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{spec("ref-a", "r", "2026-10-01", 0), spec("ref-a", "r", "2026-10-02", 0)}))

	n, err := tasks.RecordAuthFailure(ctx, accounttest.Ref("ref-a"), t0, pause)
	require.NoError(t, err)
	assert.Equal(t, 1, n)
	_, err = claim(t, tasks, accounttest.Refs("ref-a"), t0.Add(30*time.Second))
	assert.ErrorIs(t, err, ErrNoTask, "короткая пауза кабинета")

	n, err = tasks.RecordAuthFailure(ctx, accounttest.Ref("ref-a"), t0.Add(2*time.Minute), pause)
	require.NoError(t, err)
	assert.Equal(t, 2, n, "второй отказ — по кабинету, задача может быть другой")
	_, err = claim(t, tasks, accounttest.Refs("ref-a"), t0.Add(30*time.Minute))
	assert.ErrorIs(t, err, ErrNoTask, "длинная пауза")

	require.NoError(t, tasks.ResumeConnection(ctx, accounttest.Ref("ref-a")))
	n, err = tasks.RecordAuthFailure(ctx, accounttest.Ref("ref-a"), t0.Add(3*time.Hour), pause)
	require.NoError(t, err)
	assert.Equal(t, 1, n, "успех после смены ключа сбрасывает счётчик")
}

func TestStatusReport(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{
		spec("ref-a", "ozon.finance.accrual-by-day", "2026-10-01", 5), // захватится первым → done
		spec("ref-a", "ozon.finance.accrual-by-day", "2026-10-02", 0),
		spec("ref-a", "ozon.finance.accrual-types", "ref", 3),         // вторым → rate_limit
		spec("ref-a", "ozon.finance.accrual-by-day", "2026-07-31", 0), // вне окна
		spec("ref-a", "ozon.finance.realization", "2026-08", 0),       // месяц начала окна — в окне
		spec("ref-a", "ozon.finance.realization", "2026-07", 0),       // вне окна
	}))
	task, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("a")}, t0)
	require.NoError(t, err)
	task, err = claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusPending, Label: "rate_limit", ErrorClass: "rate_limit", NextRunAt: t0}, t0)
	require.NoError(t, err)
	require.NoError(t, tasks.SaveCurrentRate(ctx, accounttest.Ref("ref-a"), "/v1/finance/accrual/by-day", 0.5))
	_, err = tasks.RecordAuthFailure(ctx, accounttest.Ref("ref-b"), t0, func(int) time.Duration { return time.Hour })
	require.NoError(t, err)
	require.NoError(t, tasks.StopResourceAllConnections(ctx, "ozon.finance.mutual-settlement", "obsolete"))

	report, err := tasks.StatusAllConnections(ctx, t0.Add(time.Minute), "2026-08-01")
	require.NoError(t, err)

	require.Len(t, report.Cabinets, 2)
	byAccount := map[string]CabinetStatus{}
	for _, c := range report.Cabinets {
		byAccount[c.AccountID] = c
	}
	refA, refB := accounttest.Ref("ref-a"), accounttest.Ref("ref-b")
	a, b := byAccount[refA.AccountID], byAccount[refB.AccountID]
	assert.Equal(t, refA.CompanyID, a.CompanyID)
	require.Len(t, a.Resources, 3)
	byDay := a.Resources[0]
	assert.Equal(t, []string{"2026-08"}, a.Resources[2].MissingPeriods, "отчёт за месяц начала окна считается")
	assert.Equal(t, "ozon.finance.accrual-by-day", byDay.ResourceType)
	assert.Equal(t, 3, byDay.ByStatus[StatusPending]+byDay.ByStatus[StatusDone])
	assert.Equal(t, 1, byDay.MissingCount, "только незагруженные дни окна")
	assert.Equal(t, []string{"2026-10-02"}, byDay.MissingPeriods)
	assert.Equal(t, map[string]int{"done": 1, "rate_limit": 1}, a.Outcomes24h)
	assert.Equal(t, []Limit{{Account: accounttest.Ref("ref-a"), Endpoint: "/v1/finance/accrual/by-day", Current: 0.5}}, a.Limits)

	assert.Equal(t, refB.CompanyID, b.CompanyID)
	require.NotNil(t, b.SuspendedUntil)
	assert.Equal(t, "auth", b.SuspendReason)
	assert.Equal(t, 1, b.AuthFailures)
	assert.Equal(t, []string{"ozon.finance.mutual-settlement"}, report.StoppedResources)
}

func TestFindObjectByContentWithinTask(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{spec("ref-a", "r", "2026-10-01", 0), spec("ref-b", "r", "2026-10-01", 0)}))
	a, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	require.NoError(t, tasks.RecordPage(ctx, a.TaskKey, a.AttemptID, rawObj(1), ""))

	got, found, err := tasks.FindObject(ctx, a.TaskKey, hash("p"))
	require.NoError(t, err)
	assert.True(t, found)
	assert.Equal(t, "s3://bucket/p1", got.StorageURI)

	_, found, err = tasks.FindObject(ctx, TaskKey{Account: accounttest.Ref("ref-b"), ResourceType: "r", PeriodKey: "2026-10-01"}, hash("p"))
	require.NoError(t, err)
	assert.False(t, found, "объекты другого кабинета не переиспользуются")
	_, found, err = tasks.FindObject(ctx, a.TaskKey, hash("other"))
	require.NoError(t, err)
	assert.False(t, found)
}

func TestPlanOnceIsPerCabinet(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	a := spec("ref-a", "r", "2026-10-01", 5)
	b := spec("ref-b", "r", "2026-10-01", 5)
	a.Refresh, b.Refresh = true, true

	done, err := tasks.PlanOnce(ctx, accounttest.Ref("ref-a"), "tail", "2026-10-17", []TaskSpec{a})
	require.NoError(t, err)
	assert.True(t, done)
	done, err = tasks.PlanOnce(ctx, accounttest.Ref("ref-b"), "tail", "2026-10-17", []TaskSpec{b})
	require.NoError(t, err)
	assert.True(t, done, "кабинет, появившийся позже за тот же день, получает свою перезагрузку")
	assert.Equal(t, StatusPending, status(t, tasks, b.TaskKey))

	_, err = tasks.PlanOnce(ctx, accounttest.Ref("ref-a"), "tail", "2026-10-18", []TaskSpec{a, b})
	require.ErrorIs(t, err, ErrForeignSpec, "маркер одного подключения не ставит задачи другого")
	var marked int
	require.NoError(t, tasks.pool.QueryRow(ctx, `SELECT count(*) FROM planner_run WHERE run_date = '2026-10-18'`).Scan(&marked))
	assert.Zero(t, marked)
}

func TestRefreshOfRunningTaskAppliesAfterAttempt(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	s := spec("ref-a", "r", "2026-08-15", 1)
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
	task, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	require.NoError(t, tasks.RecordPage(ctx, task.TaskKey, task.AttemptID, rawObj(1), "cursor-1"))

	// Финальная перезагрузка M-2 пришла посреди загрузки дня.
	s.Refresh = true
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
	assert.Equal(t, StatusRunning, status(t, tasks, s.TaskKey), "lease не прерывается")

	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("partial")}, t0)
	require.NoError(t, err)
	assert.Equal(t, StatusPending, status(t, tasks, s.TaskKey), "после попытки — снова в очередь")

	again, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	assert.Empty(t, again.Cursor, "перезагрузка — с первой страницы")
	assert.Equal(t, 1, again.NextPageNo)
	assert.Empty(t, again.PageHashes)

	_, err = tasks.FinishTask(ctx, again.TaskKey, again.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("full")}, t0)
	require.NoError(t, err)
	assert.Equal(t, StatusDone, status(t, tasks, s.TaskKey), "флаг одноразовый")
}

func TestRefreshPendingSurvivesLeaseExpiryAsFullReload(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	s := spec("ref-a", "r", "2026-08-15", 1)
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
	task, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	require.NoError(t, tasks.RecordPage(ctx, task.TaskKey, task.AttemptID, rawObj(1), "cursor-1"))
	s.Refresh = true
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))

	_, err = tasks.ReleaseExpiredAcrossConnections(ctx, t0.Add(2*time.Minute))
	require.NoError(t, err)
	again, err := claim(t, tasks, accounttest.Refs("ref-a"), t0.Add(2*time.Minute))
	require.NoError(t, err)
	assert.Empty(t, again.Cursor)
	assert.Empty(t, again.PageHashes)
}

func TestRefreshOfUnfinishedTaskWithAllPagesSavedReloadsAfterIt(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	s := spec("ref-a", "ozon.orders.fbo", "2026-10-20", 5)
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
	// Последняя страница дня сохранена (курсор пуст), процесс упал до FinishTask.
	task, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	require.NoError(t, tasks.RecordPage(ctx, task.TaskKey, task.AttemptID, rawObj(1), ""))
	_, err = tasks.ReleaseExpiredAcrossConnections(ctx, t0.Add(2*time.Minute))
	require.NoError(t, err)

	s.Refresh = true
	ok, err := tasks.PlanOnce(ctx, accounttest.Ref("ref-a"), "orders_tail", "2026-10-21", []TaskSpec{s})
	require.NoError(t, err)
	require.True(t, ok)

	// Попытка закрывает прежнюю загрузку по сохранённым страницам…
	cont, err := claim(t, tasks, accounttest.Refs("ref-a"), t0.Add(2*time.Minute))
	require.NoError(t, err)
	assert.Equal(t, []string{hash("p")}, cont.PageHashes)
	_, err = tasks.FinishTask(ctx, cont.TaskKey, cont.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("a")}, t0.Add(2*time.Minute))
	require.NoError(t, err)

	// …и проход хвоста не поглощён: окно заново.
	assert.Equal(t, StatusPending, status(t, tasks, s.TaskKey))
	again, err := claim(t, tasks, accounttest.Refs("ref-a"), t0.Add(3*time.Minute))
	require.NoError(t, err)
	assert.Empty(t, again.PageHashes)
	assert.Empty(t, again.Cursor)
	assert.Equal(t, 1, again.NextPageNo)
}

func TestRefreshOfPausedTaskKeepsProgressThroughPausesThenReloads(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	s := spec("ref-a", "ozon.orders.fbo", "2026-10-21", 5)
	s.Refresh = true // «сегодня»: каждый проход планировщика
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))

	pause := func(at time.Time, page int, cursor string) {
		t.Helper()
		task, err := claim(t, tasks, accounttest.Refs("ref-a"), at)
		require.NoError(t, err)
		assert.Equal(t, page, task.NextPageNo, "продолжение с сохранённой страницы")
		require.NoError(t, tasks.RecordPage(ctx, task.TaskKey, task.AttemptID, rawObj(page), cursor))
		_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusPending, Label: "rate_limit", ErrorClass: "rate_limit", KeepCursor: true, NextRunAt: at}, at)
		require.NoError(t, err)
		// Часовой проход планировщика между паузами.
		require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
	}
	pause(t0, 1, "c1")
	pause(t0.Add(time.Hour), 2, "c2")
	pause(t0.Add(2*time.Hour), 3, "c3")

	last, err := claim(t, tasks, accounttest.Refs("ref-a"), t0.Add(3*time.Hour))
	require.NoError(t, err)
	assert.Equal(t, "c3", last.Cursor, "часовые проходы не сбрасывают прогресс")
	assert.Equal(t, 4, last.NextPageNo)
	_, err = tasks.FinishTask(ctx, last.TaskKey, last.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("day")}, t0.Add(3*time.Hour))
	require.NoError(t, err)

	assert.Equal(t, StatusPending, status(t, tasks, s.TaskKey), "отложенная перезагрузка — после итога")
	again, err := claim(t, tasks, accounttest.Refs("ref-a"), t0.Add(3*time.Hour))
	require.NoError(t, err)
	assert.Empty(t, again.Cursor)
	assert.Equal(t, 1, again.NextPageNo)
	_, err = tasks.FinishTask(ctx, again.TaskKey, again.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("day")}, t0.Add(3*time.Hour))
	require.NoError(t, err)
	assert.Equal(t, StatusDone, status(t, tasks, s.TaskKey), "флаг одноразовый")
}

func TestRefreshOfPausedTaskWithCursorIsNotSwallowed(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	s := spec("ref-a", "ozon.orders.fbo", "2026-10-20", 5)
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
	task, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	require.NoError(t, tasks.RecordPage(ctx, task.TaskKey, task.AttemptID, rawObj(1), "cursor-1"))
	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusPending, Label: "rate_limit", ErrorClass: "rate_limit", KeepCursor: true, NextRunAt: t0}, t0)
	require.NoError(t, err)

	s.Refresh = true
	ok, err := tasks.PlanOnce(ctx, accounttest.Ref("ref-a"), "orders_tail", "2026-10-21", []TaskSpec{s})
	require.NoError(t, err)
	require.True(t, ok)

	cont, err := claim(t, tasks, accounttest.Refs("ref-a"), t0.Add(time.Minute))
	require.NoError(t, err)
	assert.Equal(t, "cursor-1", cont.Cursor, "продолжение сохраняется")
	_, err = tasks.FinishTask(ctx, cont.TaskKey, cont.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("a")}, t0.Add(time.Minute))
	require.NoError(t, err)
	assert.Equal(t, StatusPending, status(t, tasks, s.TaskKey), "проход хвоста не поглощён продолжением")
}

func TestPlainUpsertKeepsCursorOfPausedTask(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	s := spec("ref-a", "ozon.orders.fbo", "2026-10-20", 5)
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
	task, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	require.NoError(t, tasks.RecordPage(ctx, task.TaskKey, task.AttemptID, rawObj(1), "cursor-1"))
	_, err = tasks.ReleaseExpiredAcrossConnections(ctx, t0.Add(2*time.Minute))
	require.NoError(t, err)

	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
	again, err := claim(t, tasks, accounttest.Refs("ref-a"), t0.Add(2*time.Minute))
	require.NoError(t, err)
	assert.Equal(t, "cursor-1", again.Cursor, "обычная постановка не сбрасывает продолжение")
	assert.Equal(t, 2, again.NextPageNo)
}

func TestContentPagesFollowLoadedRevisionAcrossAttempts(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	s := spec("ref-a", "ozon.orders.fbo", "2026-10-20", 5)
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))

	_, err := tasks.ContentPages(ctx, s.TaskKey)
	require.ErrorIs(t, err, ErrNoContent, "до первой загрузки ревизии нет")
	reloaded, err := tasks.ReloadWithoutContent(ctx, s.TaskKey, t0)
	require.NoError(t, err)
	assert.False(t, reloaded, "не done — не трогается")

	// Попытка 1: страница 1, затем 429; попытка 2 дочитывает страницу 2.
	first, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	p1 := RawObject{PageNo: 1, StorageURI: "s3://b/p1", SHA256: hash("p1"), SizeBytes: 1, StoredBytes: 1, ContentType: "application/json"}
	require.NoError(t, tasks.RecordPage(ctx, first.TaskKey, first.AttemptID, p1, "c1"))
	_, err = tasks.FinishTask(ctx, first.TaskKey, first.AttemptID, Outcome{Status: StatusPending, Label: "rate_limit", KeepCursor: true, NextRunAt: t0}, t0)
	require.NoError(t, err)
	_, err = tasks.ContentPages(ctx, s.TaskKey)
	require.ErrorIs(t, err, ErrNoContent, "промежуточный итог ревизию не создаёт")

	second, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	p2 := RawObject{PageNo: 2, StorageURI: "s3://b/p2", SHA256: hash("p2"), SizeBytes: 1, StoredBytes: 1, ContentType: "application/json"}
	require.NoError(t, tasks.RecordPage(ctx, second.TaskKey, second.AttemptID, p2, ""))
	_, err = tasks.FinishTask(ctx, second.TaskKey, second.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("rev1")}, t0)
	require.NoError(t, err)

	content, err := tasks.ContentPages(ctx, s.TaskKey)
	require.NoError(t, err)
	pages := content.Pages
	require.Len(t, pages, 2, "страницы обеих попыток")
	assert.Equal(t, []string{"s3://b/p1", "s3://b/p2"}, []string{pages[0].StorageURI, pages[1].StorageURI})
	assert.Equal(t, []int{1, 2}, []int{pages[0].PageNo, pages[1].PageNo})
	assert.False(t, content.StartedAt.IsZero(), "начало ревизии — запись её первой страницы")
	firstStart := content.StartedAt
	reloaded, err = tasks.ReloadWithoutContent(ctx, s.TaskKey, t0)
	require.NoError(t, err)
	assert.False(t, reloaded, "ревизия со страницами — не перезагружается")

	// Перезагрузка в работе: пока не done, видна прежняя ревизия.
	s.Refresh = true
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
	third, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	p3 := RawObject{PageNo: 1, StorageURI: "s3://b/p3", SHA256: hash("p3"), SizeBytes: 1, StoredBytes: 1, ContentType: "application/json"}
	require.NoError(t, tasks.RecordPage(ctx, third.TaskKey, third.AttemptID, p3, ""))
	content, err = tasks.ContentPages(ctx, s.TaskKey)
	require.NoError(t, err)
	assert.Len(t, content.Pages, 2)
	_, err = tasks.FinishTask(ctx, third.TaskKey, third.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("rev2")}, t0)
	require.NoError(t, err)
	content, err = tasks.ContentPages(ctx, s.TaskKey)
	require.NoError(t, err)
	require.Len(t, content.Pages, 1, "новая ревизия заменяет прежнюю")
	assert.Equal(t, "s3://b/p3", content.Pages[0].StorageURI)
	assert.False(t, content.StartedAt.Before(firstStart), "начало новой ревизии — её первая страница")
}

func TestContentStartIsFirstPageOfRevisionNotOfContinuation(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	s := spec("ref-a", "ozon.orders.fbo", "2026-10-20", 5)
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
	first, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	require.NoError(t, tasks.RecordPage(ctx, first.TaskKey, first.AttemptID, rawObj(1), "c1"))
	var started time.Time
	require.NoError(t, tasks.pool.QueryRow(ctx, `SELECT load_started_at FROM fetch_task WHERE period_key = '2026-10-20'`).Scan(&started))
	assert.True(t, started.Equal(t0), "начало загрузки — начало попытки (до запроса), не время записи страницы")
	_, err = tasks.FinishTask(ctx, first.TaskKey, first.AttemptID, Outcome{Status: StatusPending, Label: "rate_limit", KeepCursor: true, NextRunAt: t0}, t0)
	require.NoError(t, err)

	cont, err := claim(t, tasks, accounttest.Refs("ref-a"), t0.Add(2*time.Hour))
	require.NoError(t, err)
	require.NoError(t, tasks.RecordPage(ctx, cont.TaskKey, cont.AttemptID, rawObj(2), ""))
	_, err = tasks.FinishTask(ctx, cont.TaskKey, cont.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("a")}, t0)
	require.NoError(t, err)

	content, err := tasks.ContentPages(ctx, s.TaskKey)
	require.NoError(t, err)
	assert.True(t, content.StartedAt.Equal(t0), "продолжение не сдвигает начало ревизии")
}

func TestContentWithoutPagesListIsReloadedAndMissingObjectIsAnError(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	s := spec("ref-a", "ozon.orders.fbo", "2026-10-19", 5)
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{s}))
	task, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	require.NoError(t, tasks.RecordPage(ctx, task.TaskKey, task.AttemptID, rawObj(1), ""))
	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("a")}, t0)
	require.NoError(t, err)

	// Ревизия до миграции 00004: done без списка страниц.
	_, err = tasks.pool.Exec(ctx, `UPDATE fetch_task SET content_pages = '' WHERE period_key = '2026-10-19'`)
	require.NoError(t, err)
	_, err = tasks.ContentPages(ctx, s.TaskKey)
	require.ErrorIs(t, err, ErrNoContent)
	reloaded, err := tasks.ReloadWithoutContent(ctx, s.TaskKey, t0.Add(time.Minute))
	require.NoError(t, err)
	assert.True(t, reloaded)
	assert.Equal(t, StatusPending, status(t, tasks, s.TaskKey))

	// Загрузка, начатая до миграции и завершённая после: страницы есть,
	// начала ревизии нет — тоже на перезагрузку.
	again, err := claim(t, tasks, accounttest.Refs("ref-a"), t0.Add(time.Minute))
	require.NoError(t, err)
	require.NoError(t, tasks.RecordPage(ctx, again.TaskKey, again.AttemptID, rawObj(1), ""))
	_, err = tasks.FinishTask(ctx, again.TaskKey, again.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("b")}, t0.Add(time.Minute))
	require.NoError(t, err)
	_, err = tasks.pool.Exec(ctx, `UPDATE fetch_task SET content_started_at = NULL WHERE period_key = '2026-10-19'`)
	require.NoError(t, err)
	_, err = tasks.ContentPages(ctx, s.TaskKey)
	require.ErrorIs(t, err, ErrNoContent)
	reloaded, err = tasks.ReloadWithoutContent(ctx, s.TaskKey, t0.Add(2*time.Minute))
	require.NoError(t, err)
	assert.True(t, reloaded, "без начала ревизии — перезагрузка")

	// Страница без объекта — ошибка, не укороченный список.
	_, err = tasks.pool.Exec(ctx, `UPDATE fetch_task SET content_pages = $1, content_started_at = now() WHERE period_key = '2026-10-19'`, hash("p")+","+hash("lost"))
	require.NoError(t, err)
	_, err = tasks.ContentPages(ctx, s.TaskKey)
	require.ErrorIs(t, err, ErrMissingObject)
}

func TestCoverageCountsActiveCabinetsWithinWindow(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{
		spec("ref-a", "by-day", "2026-10-15", 9),
		spec("ref-b", "by-day", "2026-10-15", 0),
		spec("ref-gone", "by-day", "2026-10-15", 0), // неактивный кабинет
		spec("ref-a", "by-day", "2026-07-31", 0),    // вне окна
		spec("ref-a", "realization", "2026-09", 0),
		spec("ref-a", "realization", "2026-07", 0), // вне окна
		spec("ref-a", "types", "ref", 0),
	}))
	task, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	require.Equal(t, "2026-10-15", task.PeriodKey, "приоритет 9 — первым")
	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("a")}, t0)
	require.NoError(t, err)

	rows, err := tasks.CoverageAcrossConnections(ctx, accounttest.Refs("ref-a", "ref-b"), "2026-08-01", "2026-10-31")
	require.NoError(t, err)

	require.Len(t, rows, 3)
	assert.Equal(t, "by-day", rows[0].ResourceType)
	assert.Equal(t, "2026-10-15", rows[0].PeriodKey)
	assert.Equal(t, 1, rows[0].ByStatus[StatusDone])
	assert.Equal(t, 1, rows[0].ByStatus[StatusPending], "ref-gone не считается")
	assert.Equal(t, 0, rows[0].ByStatus[StatusFailedPermanent], "все статусы контракта присутствуют")
	require.NotNil(t, rows[0].LastSuccessAt)
	assert.Equal(t, CoverageRow{ResourceType: "realization", PeriodKey: "2026-09", ByStatus: map[string]int{"done": 0, "running": 0, "pending": 1, "not_ready": 0, "failed_retryable": 0, "failed_permanent": 0}}, rows[1])
	assert.Equal(t, "ref", rows[2].PeriodKey)

	empty, err := tasks.CoverageAcrossConnections(ctx, nil, "2026-08-01", "2026-10-31")
	require.NoError(t, err)
	assert.Empty(t, empty)
}

func TestRateLimitedTaskYieldsToReadyTaskOfSamePriority(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	accrual := spec("a", "ozon.finance.accrual-postings", "2026-10-20", 1)
	accrual.RunAt = t0.Add(time.Second) // повтор после 429 с Retry-After
	backfill := spec("a", "ozon.finance.accrual-by-day", "2026-10-01", 1)
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{accrual, backfill}))

	got, err := claim(t, tasks, accounttest.Refs("a"), t0.Add(2*time.Second))
	require.NoError(t, err)
	assert.Equal(t, backfill.TaskKey, got.TaskKey, "догон кабинета не ждёт за 429 общей квоты")
}

func TestAdsAndSellerTasksOfCabinetRunInParallel(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{
		spec("a", "ozon.finance.accrual-by-day", "2026-10-20", 5),
		spec("a", "ozon.orders.fbo", "2026-10-20", 5),
		spec("a", "ozon.ads.expense", "2026-10-20", 5),
		spec("a", "ozon.ads.daily", "2026-10-20", 5),
	}))

	first, err := claim(t, tasks, accounttest.Refs("a"), t0)
	require.NoError(t, err)
	second, err := claim(t, tasks, accounttest.Refs("a"), t0)
	require.NoError(t, err)
	_, err = claim(t, tasks, accounttest.Refs("a"), t0)
	assert.ErrorIs(t, err, ErrNoTask, "по одной задаче на семейство API")

	families := map[bool]int{}
	for _, tk := range []Task{first, second} {
		families[strings.HasPrefix(tk.ResourceType, "ozon.ads.")]++
	}
	assert.Equal(t, map[bool]int{true: 1, false: 1}, families, "реклама и seller — параллельно")

	// Индекс — последний рубеж: вторая running-задача семейства не записывается.
	_, err = tasks.pool.Exec(ctx, `UPDATE fetch_task SET status = 'running', lease_until = $1
		WHERE company_id = $2 AND marketplace_account_id = $3 AND status = 'pending' AND resource_type LIKE 'ozon.ads.%'`,
		t0.Add(time.Minute), accounttest.Ref("a").CompanyID, accounttest.Ref("a").AccountID)
	assert.True(t, isUniqueViolation(err), "две running-задачи рекламы кабинета")
}

func TestSellerSuspensionDoesNotStopAds(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{
		spec("a", "ozon.finance.accrual-by-day", "2026-10-20", 5),
		spec("a", "ozon.ads.expense", "2026-10-20", 5),
	}))
	require.NoError(t, tasks.SuspendConnection(ctx, accounttest.Ref("a"), "auth", t0.Add(time.Hour)))

	got, err := claim(t, tasks, accounttest.Refs("a"), t0)
	require.NoError(t, err)
	assert.Equal(t, "ozon.ads.expense", got.ResourceType, "пауза ключа seller рекламу не останавливает")
	_, err = claim(t, tasks, accounttest.Refs("a"), t0)
	assert.ErrorIs(t, err, ErrNoTask, "seller на паузе")
}

func TestSaveCursorAndAdsReportInFlight(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	daily := spec("a", "ozon.ads.statistics", "2026-10-21", 5)
	tail := spec("a", "ozon.ads.statistics.tail", "2026-10-22", 5)
	other := spec("b", "ozon.ads.statistics", "2026-10-21", 5)
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{daily, tail, other}))

	busy, err := tasks.AdsReportInFlight(ctx, tail.TaskKey)
	require.NoError(t, err)
	assert.False(t, busy)

	got, err := claim(t, tasks, accounttest.Refs("a"), t0)
	require.NoError(t, err)
	require.NoError(t, tasks.SaveCursor(ctx, got.TaskKey, got.AttemptID, "fp:0:uuid-1:1700000000:0"))
	assert.ErrorIs(t, tasks.SaveCursor(ctx, got.TaskKey, newAttemptID(), "x"), ErrLeaseLost)

	otherKey := tail.TaskKey
	if got.TaskKey == tail.TaskKey {
		otherKey = daily.TaskKey
	}
	busy, err = tasks.AdsReportInFlight(ctx, otherKey)
	require.NoError(t, err)
	assert.True(t, busy, "отчёт другой задачи кабинета заказан")
	busy, err = tasks.AdsReportInFlight(ctx, got.TaskKey)
	require.NoError(t, err)
	assert.False(t, busy, "своя задача не мешает")
	busy, err = tasks.AdsReportInFlight(ctx, other.TaskKey)
	require.NoError(t, err)
	assert.False(t, busy, "другой кабинет не мешает")

	// Освобождение с курсором (отчёт ещё не готов) — UUID сохраняется.
	_, err = tasks.FinishTask(ctx, got.TaskKey, got.AttemptID, Outcome{Status: StatusPending, Label: "report_pending", KeepCursor: true, NextRunAt: t0}, t0)
	require.NoError(t, err)
	again, err := claim(t, tasks, accounttest.Refs("a"), t0)
	require.NoError(t, err)
	if again.TaskKey == got.TaskKey {
		assert.Equal(t, "fp:0:uuid-1:1700000000:0", again.Cursor)
	}
	require.NoError(t, tasks.SaveCursor(ctx, again.TaskKey, again.AttemptID, "fp:1::0:0"))
}
