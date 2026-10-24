package planner

import (
	"context"
	"io"
	"log/slog"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account/accounttest"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/executor"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

var msk = func() *time.Location {
	loc, err := time.LoadLocation("Europe/Moscow")
	if err != nil {
		panic(err)
	}
	return loc
}()

var allFinance = []string{executor.ResourceAccrualByDay, executor.ResourceAccrualTypes, executor.ResourceRealization, executor.ResourceMutualSettlement}

func seller(ref string, resources ...string) connections.Cabinet {
	return connections.Cabinet{Account: accounttest.Ref(ref), Seller: &ozon.Credentials{ClientID: "c", APIKey: "k"}, ResourceTypes: resources}
}

func at(s string) time.Time {
	t, err := time.ParseInLocation("2006-01-02 15:04", s, msk)
	if err != nil {
		panic(err)
	}
	return t
}

func periods(specs []store.TaskSpec, resource string) []string {
	var out []string
	for _, s := range specs {
		if s.ResourceType == resource {
			out = append(out, s.PeriodKey)
		}
	}
	return out
}

func refresh(p Plan, kind string) *Refresh {
	for i := range p.Refreshes {
		if p.Refreshes[i].Kind == kind {
			return &p.Refreshes[i]
		}
	}
	return nil
}

func TestEnsureCoversCurrentAndTwoPreviousMonthsUpToYesterday(t *testing.T) {
	p := Build(at("2026-10-17 12:00"), []connections.Cabinet{seller("a", allFinance...)}, DefaultConfig(msk))

	days := periods(p.Ensure, executor.ResourceAccrualByDay)
	assert.Equal(t, "2026-08-01", days[0])
	assert.Equal(t, "2026-10-16", days[len(days)-1], "сегодняшний день не грузится")
	assert.Len(t, days, 31+30+16)
	for _, s := range p.Ensure {
		if s.ResourceType != executor.ResourceAccrualByDay {
			continue
		}
		assert.False(t, s.Refresh, "ensure не перезагружает")
		if s.PeriodKey >= "2026-10-01" {
			assert.Equal(t, PriorityCurrentMonth, s.Priority, s.PeriodKey)
		} else {
			assert.Equal(t, PriorityBackfill, s.Priority, s.PeriodKey)
		}
	}
	assert.Equal(t, []string{"2026-09", "2026-08"}, periods(p.Ensure, executor.ResourceRealization))
	assert.Equal(t, []string{"2026-09", "2026-08"}, periods(p.Ensure, executor.ResourceMutualSettlement))
}

func TestMoscowMidnightDecidesTheDay(t *testing.T) {
	// 2026-10-16 22:30 UTC = 2026-10-17 01:30 МСК: «вчера» — уже 16-е.
	p := Build(time.Date(2026, 10, 16, 22, 30, 0, 0, time.UTC), []connections.Cabinet{seller("a", allFinance...)}, DefaultConfig(msk))
	days := periods(p.Ensure, executor.ResourceAccrualByDay)
	assert.Equal(t, "2026-10-16", days[len(days)-1])
	assert.Equal(t, "2026-10-17", refresh(p, KindTail).RunDate)
}

func TestTailRefreshesYesterdayAndKDaysAcrossMonthBoundary(t *testing.T) {
	p := Build(at("2026-11-02 08:00"), []connections.Cabinet{seller("a", allFinance...)}, DefaultConfig(msk))

	tail := refresh(p, KindTail)
	require.NotNil(t, tail)
	assert.Equal(t, "2026-11-02", tail.RunDate)
	assert.Equal(t, []string{"2026-11-01", "2026-10-31", "2026-10-30", "2026-10-29"}, periods(tail.Specs, executor.ResourceAccrualByDay))
	for _, s := range tail.Specs {
		assert.True(t, s.Refresh)
		assert.Equal(t, PriorityRoutine, s.Priority)
	}
}

func TestFirstDayOfMonthAndOfYear(t *testing.T) {
	p := Build(at("2027-01-01 00:10"), []connections.Cabinet{seller("a", allFinance...)}, DefaultConfig(msk))

	days := periods(p.Ensure, executor.ResourceAccrualByDay)
	assert.Equal(t, "2026-11-01", days[0])
	assert.Equal(t, "2026-12-31", days[len(days)-1], "текущий месяц ещё пуст — только M-1 и M-2")
	assert.Equal(t, []string{"2026-12", "2026-11"}, periods(p.Ensure, executor.ResourceRealization), "1-го числа — уже за прошлый месяц")
}

func TestWeeklyReconcileOnlyOnConfiguredWeekday(t *testing.T) {
	cabs := []connections.Cabinet{seller("a", allFinance...)}
	monday := Build(at("2026-10-19 09:00"), cabs, DefaultConfig(msk))
	tuesday := Build(at("2026-10-20 09:00"), cabs, DefaultConfig(msk))

	rec := refresh(monday, KindReconcile)
	require.NotNil(t, rec)
	days := periods(rec.Specs, executor.ResourceAccrualByDay)
	assert.Equal(t, "2026-09-01", days[0])
	assert.Equal(t, "2026-09-30", days[len(days)-1])
	assert.Len(t, days, 30)
	assert.Nil(t, refresh(tuesday, KindReconcile))
}

func TestFinalM2OnlyOnFinalDay(t *testing.T) {
	cabs := []connections.Cabinet{seller("a", allFinance...)}
	tenth := Build(at("2026-10-10 09:00"), cabs, DefaultConfig(msk))
	eleventh := Build(at("2026-10-11 09:00"), cabs, DefaultConfig(msk))

	final := refresh(tenth, KindFinal)
	require.NotNil(t, final)
	days := periods(final.Specs, executor.ResourceAccrualByDay)
	assert.Equal(t, "2026-08-01", days[0])
	assert.Equal(t, "2026-08-31", days[len(days)-1])
	assert.Nil(t, refresh(eleventh, KindFinal))
}

func TestNothingOlderThanM2(t *testing.T) {
	p := Build(at("2026-10-10 09:00"), []connections.Cabinet{seller("a", allFinance...)}, DefaultConfig(msk))
	all := append([]store.TaskSpec(nil), p.Ensure...)
	for _, r := range p.Refreshes {
		all = append(all, r.Specs...)
	}
	for _, s := range all {
		if s.PeriodKey == executor.PeriodRef {
			continue
		}
		assert.GreaterOrEqual(t, s.PeriodKey, "2026-08", s.PeriodKey)
	}
}

func TestAccrualTypesDaily(t *testing.T) {
	types := refresh(Build(at("2026-10-17 09:00"), []connections.Cabinet{seller("a", allFinance...)}, DefaultConfig(msk)), KindTypes)
	require.NotNil(t, types)
	require.Len(t, types.Specs, 1)
	assert.Equal(t, executor.PeriodRef, types.Specs[0].PeriodKey)
	assert.True(t, types.Specs[0].Refresh)
}

func TestCabinetsWithoutSellerOrResourcesGetNothing(t *testing.T) {
	cabs := []connections.Cabinet{
		{Account: accounttest.Ref("perf-only"), HasPerformance: true, ResourceTypes: []string{"ozon.ads.statistics"}},
		seller("types-only", executor.ResourceAccrualTypes),
	}
	p := Build(at("2026-10-19 09:00"), cabs, DefaultConfig(msk))

	for _, s := range p.Ensure {
		assert.Equal(t, accounttest.Ref("types-only"), s.Account)
		assert.Equal(t, executor.ResourceAccrualTypes, s.ResourceType)
	}
	for _, r := range p.Refreshes {
		for _, s := range r.Specs {
			assert.Equal(t, accounttest.Ref("types-only"), s.Account)
			assert.Equal(t, executor.ResourceAccrualTypes, s.ResourceType)
		}
	}
}

type fakeStore struct {
	mu      sync.Mutex
	ensured int
	runs    map[string]bool
}

func (f *fakeStore) Upsert(_ context.Context, specs []store.TaskSpec) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.ensured += len(specs)
	return nil
}

func (f *fakeStore) PlanOnce(_ context.Context, ref account.Ref, kind, runDate string, _ []store.TaskSpec) (bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.runs == nil {
		f.runs = map[string]bool{}
	}
	key := kind + "/" + runDate + "/" + accounttest.Label(ref)
	if f.runs[key] {
		return false, nil
	}
	f.runs[key] = true
	return true, nil
}

type fakeCabs struct {
	ready bool
	list  []connections.Cabinet
	late  *atomic.Bool // если задан — готовность берётся отсюда
}

func (f fakeCabs) Ready() bool {
	if f.late != nil {
		return f.late.Load()
	}
	return f.ready
}
func (f fakeCabs) List() []connections.Cabinet { return f.list }

func TestSchedulerTicks(t *testing.T) {
	st := &fakeStore{}
	logger := slog.New(slog.NewTextHandler(io.Discard, nil))
	now := func() time.Time { return at("2026-10-19 09:00") }

	NewScheduler(DefaultConfig(msk), time.Hour, st, fakeCabs{ready: false}, logger, now).Tick(context.Background())
	assert.Zero(t, st.ensured, "без снимка ничего не ставится")

	s := NewScheduler(DefaultConfig(msk), time.Hour, st, fakeCabs{ready: true, list: []connections.Cabinet{seller("a", allFinance...)}}, logger, now)
	s.Tick(context.Background())
	s.Tick(context.Background())
	assert.True(t, st.runs[KindTail+"/2026-10-19/a"])
	assert.True(t, st.runs[KindReconcile+"/2026-10-19/a"])
	assert.Len(t, st.runs, 3, "tail, accrual_types, reconcile_m1 — по разу")
}

func TestSchedulerRunStopsOnCancel(t *testing.T) {
	st := &fakeStore{}
	s := NewScheduler(DefaultConfig(msk), 5*time.Millisecond, st, fakeCabs{ready: true, list: []connections.Cabinet{seller("a", allFinance...)}},
		slog.New(slog.NewTextHandler(io.Discard, nil)), time.Now)
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		s.Run(ctx)
		close(done)
	}()
	require.Eventually(t, func() bool {
		st.mu.Lock()
		defer st.mu.Unlock()
		return st.ensured > 0
	}, time.Second, time.Millisecond)
	cancel()
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("Run did not stop")
	}
}

func TestSchedulerRetriesSoonUntilSnapshotArrives(t *testing.T) {
	st := &fakeStore{}
	late := &atomic.Bool{}
	s := NewScheduler(DefaultConfig(msk), time.Hour, st, fakeCabs{late: late, list: []connections.Cabinet{seller("a", allFinance...)}},
		slog.New(slog.NewTextHandler(io.Discard, nil)), time.Now)
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		s.Run(ctx)
		close(done)
	}()
	t.Cleanup(func() {
		cancel()
		<-done
	})
	time.AfterFunc(50*time.Millisecond, func() { late.Store(true) })

	require.Eventually(t, func() bool {
		st.mu.Lock()
		defer st.mu.Unlock()
		return st.ensured > 0
	}, NotReadyRetry+3*time.Second, 50*time.Millisecond, "проход — вскоре после появления снимка, не через час")
}

func TestRefreshesAreBuiltPerCabinetAndTypesAreEnsured(t *testing.T) {
	p := Build(at("2026-10-17 09:00"), []connections.Cabinet{seller("a", allFinance...), seller("b", allFinance...)}, DefaultConfig(msk))

	byRef := map[string][]string{}
	for _, r := range p.Refreshes {
		byRef[accounttest.Label(r.Account)] = append(byRef[accounttest.Label(r.Account)], r.Kind)
		for _, s := range r.Specs {
			assert.Equal(t, r.Account, s.Account, "проход кабинета — только его задачи")
		}
	}
	assert.ElementsMatch(t, []string{KindTail, KindTypes}, byRef["a"])
	assert.ElementsMatch(t, []string{KindTail, KindTypes}, byRef["b"])
	assert.Contains(t, periods(p.Ensure, executor.ResourceAccrualTypes), executor.PeriodRef, "справочник новому кабинету — без ожидания перезагрузки")
}
