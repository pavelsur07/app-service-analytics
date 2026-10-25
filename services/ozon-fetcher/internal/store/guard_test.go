package store

import (
	"context"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account/accounttest"
)

// Остановка всей загрузки держит выдачу задач всех подключений, пока её
// не снимут вручную; повторная остановка первую не перезаписывает.
func TestHaltStopsClaimsOfAllConnectionsUntilResumed(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{spec("ref-a", "r", "2026-10-01", 0), spec("ref-b", "r", "2026-10-01", 0)}))

	halted, err := tasks.HaltAllConnections(ctx, accounttest.Ref("ref-a"), "r", "first")
	require.NoError(t, err)
	assert.True(t, halted)
	again, err := tasks.HaltAllConnections(ctx, accounttest.Ref("ref-b"), "r", "second")
	require.NoError(t, err)
	assert.False(t, again, "остановка уже стоит")

	_, err = claim(t, tasks, accounttest.Refs("ref-a", "ref-b"), t0.Add(24*time.Hour))
	require.ErrorIs(t, err, ErrNoTask, "остановка не истекает сама")

	resumed, err := tasks.ResumeAllConnections(ctx)
	require.NoError(t, err)
	assert.True(t, resumed)
	_, err = claim(t, tasks, accounttest.Refs("ref-a", "ref-b"), t0)
	require.NoError(t, err)

	resumed, err = tasks.ResumeAllConnections(ctx)
	require.NoError(t, err)
	assert.False(t, resumed, "снимать нечего")
}

// Пауза ресурса подключения не задевает другой ресурс этого подключения и
// тот же ресурс другого подключения; по истечении — задача выдаётся.
func TestResourcePauseIsScopedToConnectionAndResource(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{
		spec("ref-a", "paused", "2026-10-01", 9),
		spec("ref-a", "other", "2026-10-01", 0),
		spec("ref-b", "paused", "2026-10-01", 0),
	}))
	require.NoError(t, tasks.PauseResource(ctx, accounttest.Ref("ref-a"), "paused", t0.Add(time.Hour), "forbidden"))

	first, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	assert.Equal(t, "other", first.ResourceType, "приоритет у паузы выше, но она на паузе")
	other, err := claim(t, tasks, accounttest.Refs("ref-b"), t0)
	require.NoError(t, err)
	assert.Equal(t, "paused", other.ResourceType, "у другого подключения ресурс не на паузе")

	_, err = tasks.FinishTask(ctx, first.TaskKey, first.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("a")}, t0)
	require.NoError(t, err)
	_, err = claim(t, tasks, accounttest.Refs("ref-a"), t0.Add(2*time.Hour))
	require.NoError(t, err, "пауза истекла")
}

// «Метод уже отвечал 200 этим ключом» — попытка ресурса этого подключения
// с той же версией ключей: завершённая успехом или записавшая страницу.
// Попытка, начатая после смены ключа, но со старой версией, не считается.
func TestServedWithKeyReadsAttemptsOfThisKeyVersion(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	key := t0.Add(-time.Hour).Add(123456789 * time.Nanosecond) // точность выдачи выше, чем у PostgreSQL
	oldKey := key.Add(-24 * time.Hour)
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{
		spec("ref-a", "done", "2026-10-01", 0), spec("ref-a", "failed", "2026-10-01", 0),
		spec("ref-a", "paged", "2026-10-01", 0), spec("ref-a", "stale", "2026-10-01", 0),
	}))
	transient := Outcome{Status: StatusFailedRetryable, Label: "transient", ErrorClass: "transient", KeepCursor: true, NextRunAt: t0.Add(time.Hour)}
	done := Outcome{Status: StatusDone, Label: "done", ContentHash: hash("a")}
	attempts := map[string]struct {
		keyVersion time.Time
		out        Outcome
		pages      int
	}{
		"done":   {key, done, 0},
		"failed": {key, transient, 0},
		"paged":  {key, transient, 2},
		"stale":  {oldKey, done, 0},
	}
	for range attempts {
		task, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
		require.NoError(t, err)
		a := attempts[task.ResourceType]
		require.NoError(t, tasks.MarkAttemptKey(ctx, task.Account, task.AttemptID, a.keyVersion))
		for i := range a.pages {
			require.NoError(t, tasks.RecordPage(ctx, task.TaskKey, task.AttemptID, RawObject{
				PageNo: i + 1, StorageURI: "s3://b/k", SHA256: hash(task.ResourceType), SizeBytes: 1, StoredBytes: 1, ContentType: "application/json",
			}, "next"))
		}
		_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, a.out, t0)
		require.NoError(t, err)
	}

	for name, tc := range map[string]struct {
		ref      string
		resource string
		want     bool
	}{
		"success with this key":           {"ref-a", "done", true},
		"interrupted after stored pages":  {"ref-a", "paged", true},
		"only failed attempts":            {"ref-a", "failed", false},
		"success with previous key":       {"ref-a", "stale", false},
		"same resource, other connection": {"ref-b", "done", false},
	} {
		t.Run(name, func(t *testing.T) {
			ok, err := tasks.ServedWithKey(ctx, accounttest.Ref(tc.ref), tc.resource, key)
			require.NoError(t, err)
			assert.Equal(t, tc.want, ok)
		})
	}
}
