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

// «Метод уже отвечал 200 этим ключом» — успешная попытка ресурса этого
// подключения после последнего изменения ключей.
func TestSucceededSinceReadsSuccessfulAttemptsAfterKeyChange(t *testing.T) {
	tasks := testTasks(t)
	ctx := context.Background()
	require.NoError(t, tasks.UpsertAcrossConnections(ctx, []TaskSpec{spec("ref-a", "r", "2026-10-01", 0), spec("ref-a", "failed", "2026-10-01", 0)}))
	task, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	_, err = tasks.FinishTask(ctx, task.TaskKey, task.AttemptID, Outcome{Status: StatusDone, Label: "done", ContentHash: hash("a")}, t0)
	require.NoError(t, err)
	failed, err := claim(t, tasks, accounttest.Refs("ref-a"), t0)
	require.NoError(t, err)
	_, err = tasks.FinishTask(ctx, failed.TaskKey, failed.AttemptID, Outcome{Status: StatusFailedRetryable, Label: "transient", ErrorClass: "transient", NextRunAt: t0}, t0)
	require.NoError(t, err)

	for name, tc := range map[string]struct {
		ref      string
		resource string
		since    time.Time
		want     bool
	}{
		"success after key change":     {"ref-a", task.ResourceType, t0.Add(-time.Hour), true},
		"success before key change":    {"ref-a", task.ResourceType, t0.Add(time.Hour), false},
		"only failed attempts":         {"ref-a", failed.ResourceType, t0.Add(-time.Hour), false},
		"same resource, other account": {"ref-b", task.ResourceType, t0.Add(-time.Hour), false},
	} {
		t.Run(name, func(t *testing.T) {
			ok, err := tasks.SucceededSince(ctx, accounttest.Ref(tc.ref), tc.resource, tc.since)
			require.NoError(t, err)
			assert.Equal(t, tc.want, ok)
		})
	}
}
