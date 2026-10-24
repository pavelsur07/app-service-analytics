package ratelimit

import (
	"context"
	"sync"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account/accounttest"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
)

var (
	refA = accounttest.Ref("conn-a")
	refB = accounttest.Ref("conn-b")
)

const (
	ep  = ozon.EndpointAccrualByDay
	ep2 = ozon.EndpointAccrualTypes
	ep3 = ozon.EndpointAccrualPostings
)

type fakeClock struct {
	mu     sync.Mutex
	now    time.Time
	sleeps []time.Duration
}

func (c *fakeClock) Now() time.Time {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.now
}

func (c *fakeClock) Sleep(ctx context.Context, d time.Duration) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	c.sleeps = append(c.sleeps, d)
	c.now = c.now.Add(d)
	return nil
}

type changes struct {
	mu   sync.Mutex
	list []float64
}

func (c *changes) record(_ account.Ref, _ string, rps float64) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.list = append(c.list, rps)
}

func testConfig() Config {
	return Config{
		Endpoints: map[string]EndpointLimit{
			ep:  {Start: 1, Floor: 0.1, Ceiling: 5, DefaultPause: 10 * time.Second},
			ep2: {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: time.Minute},
			ep3: {Start: 0.2, Floor: 0.2, Ceiling: 0.2, DefaultPause: 5 * time.Second, SharedQuota: true},
		},
		Fallback:     EndpointLimit{Start: 0.5, Floor: 0.1, Ceiling: 1, DefaultPause: time.Minute},
		GrowAfter:    3,
		IncreaseStep: 0.5,
	}
}

func newRegistry() (*Registry, *fakeClock, *changes) {
	clock := &fakeClock{now: time.Date(2026, 10, 17, 12, 0, 0, 0, time.UTC)}
	ch := &changes{}
	return New(testConfig(), clock.Now, clock.Sleep, ch.record), clock, ch
}

func rateLimited(retry time.Duration) *ozon.APIError {
	return &ozon.APIError{Class: ozon.ClassRateLimit, RetryAfter: retry}
}

func TestStartsFromEndpointDefaultsAndFallback(t *testing.T) {
	r, _, _ := newRegistry()
	assert.InDelta(t, 1.0, r.Rate(refA, ep), 1e-9)
	assert.InDelta(t, 0.2, r.Rate(refA, ep2), 1e-9)
	assert.InDelta(t, 0.5, r.Rate(refA, "/v9/unknown"), 1e-9)
}

func TestRateLimitIsIsolatedPerCabinetAndEndpoint(t *testing.T) {
	r, _, _ := newRegistry()
	r.Observe(refA, ep, rateLimited(0))

	assert.InDelta(t, 0.5, r.Rate(refA, ep), 1e-9)
	assert.InDelta(t, 1.0, r.Rate(refB, ep), 1e-9, "другой кабинет не замедлился")
	assert.InDelta(t, 0.2, r.Rate(refA, ep2), 1e-9, "другой endpoint того же кабинета не замедлился")
}

func TestRateLimitHalvesDownToFloor(t *testing.T) {
	r, _, ch := newRegistry()
	for range 10 {
		r.Observe(refA, ep, rateLimited(0))
	}
	assert.InDelta(t, 0.1, r.Rate(refA, ep), 1e-9)
	assert.Equal(t, []float64{0.5, 0.25, 0.125, 0.1}, ch.list, "OnChange — только на изменение")
}

func TestSuccessesGrowUpToCeiling(t *testing.T) {
	r, _, _ := newRegistry()
	for range 2 {
		r.Observe(refA, ep, nil)
	}
	assert.InDelta(t, 1.0, r.Rate(refA, ep), 1e-9, "рост только после GrowAfter успехов")
	r.Observe(refA, ep, nil)
	assert.InDelta(t, 1.5, r.Rate(refA, ep), 1e-9)

	for range 100 {
		r.Observe(refA, ep, nil)
	}
	assert.InDelta(t, 5.0, r.Rate(refA, ep), 1e-9)
}

func TestRateLimitResetsSuccessStreak(t *testing.T) {
	r, _, _ := newRegistry()
	r.Observe(refA, ep, nil)
	r.Observe(refA, ep, nil)
	r.Observe(refA, ep, rateLimited(0))
	r.Observe(refA, ep, nil)
	r.Observe(refA, ep, nil)
	assert.InDelta(t, 0.5, r.Rate(refA, ep), 1e-9)
}

func TestAnyErrorBreaksSuccessStreak(t *testing.T) {
	r, _, _ := newRegistry()
	r.Observe(refA, ep, nil)
	r.Observe(refA, ep, nil)
	r.Observe(refA, ep, &ozon.APIError{Class: ozon.ClassTransient})
	r.Observe(refA, ep, nil)
	assert.InDelta(t, 1.0, r.Rate(refA, ep), 1e-9, "успехи не подряд — роста нет")
	r.Observe(refA, ep, nil)
	r.Observe(refA, ep, nil)
	assert.InDelta(t, 1.5, r.Rate(refA, ep), 1e-9)
}

func TestCallerQueuedForTokenRespectsPauseSetMeanwhile(t *testing.T) {
	clock := &fakeClock{now: time.Date(2026, 10, 17, 12, 0, 0, 0, time.UTC)}
	r := New(Config{Fallback: EndpointLimit{Start: 5, Floor: 5, Ceiling: 5, DefaultPause: time.Minute}}, clock.Now, clock.Sleep, nil)
	require.NoError(t, r.Wait(context.Background(), refA, "/x"), "первый токен — сразу")

	done := make(chan error, 1)
	go func() { done <- r.Wait(context.Background(), refA, "/x") }() // ждёт второй токен ~200 мс
	time.Sleep(20 * time.Millisecond)
	r.Observe(refA, "/x", rateLimited(time.Hour)) // соседу пришёл 429, пока B в очереди

	select {
	case err := <-done:
		require.NoError(t, err)
	case <-time.After(3 * time.Second):
		t.Fatal("Wait did not return")
	}
	clock.mu.Lock()
	defer clock.mu.Unlock()
	require.NotEmpty(t, clock.sleeps, "ожидавший токен не проскочил паузу")
	assert.InDelta(t, time.Hour.Seconds(), clock.sleeps[0].Seconds(), 1)
}

func TestOtherErrorsDoNotChangeRate(t *testing.T) {
	r, _, ch := newRegistry()
	for _, class := range []ozon.Class{ozon.ClassTransient, ozon.ClassAuth, ozon.ClassInvalidRequest, ozon.ClassNotReady} {
		r.Observe(refA, ep, &ozon.APIError{Class: class})
	}
	assert.InDelta(t, 1.0, r.Rate(refA, ep), 1e-9)
	assert.Empty(t, ch.list)
}

func TestOverrideCapsCeilingAndSetsStart(t *testing.T) {
	r, _, _ := newRegistry()
	r.Seed(refA, ep, 2, 0)
	assert.InDelta(t, 2.0, r.Rate(refA, ep), 1e-9)

	for range 100 {
		r.Observe(refA, ep, nil)
	}
	assert.InDelta(t, 2.0, r.Rate(refA, ep), 1e-9, "ручное значение — потолок")

	r.SetOverride(refA, ep, 0.5)
	assert.InDelta(t, 0.5, r.Rate(refA, ep), 1e-9, "снижение ручного значения сразу снижает скорость")
	r.SetOverride(refA, ep, 0)
	for range 100 {
		r.Observe(refA, ep, nil)
	}
	assert.InDelta(t, 5.0, r.Rate(refA, ep), 1e-9, "без ручного значения потолок — Ceiling")
}

func TestSeedRestoresSavedRateWithinBounds(t *testing.T) {
	r, _, _ := newRegistry()
	r.Seed(refA, ep, 0, 0.3)
	assert.InDelta(t, 0.3, r.Rate(refA, ep), 1e-9)

	r.Seed(refB, ep, 0, 50)
	assert.InDelta(t, 5.0, r.Rate(refB, ep), 1e-9, "сохранённое значение зажато потолком")

	r.Seed(accounttest.Ref("conn-c"), ep, 3, 4)
	assert.InDelta(t, 3.0, r.Rate(accounttest.Ref("conn-c"), ep), 1e-9, "и ручным значением")
}

func TestWaitHonoursRetryAfterThenDefaultPause(t *testing.T) {
	r, clock, _ := newRegistry()

	r.Observe(refA, ep, rateLimited(7*time.Second))
	require.NoError(t, r.Wait(context.Background(), refA, ep))
	assert.Equal(t, []time.Duration{7 * time.Second}, clock.sleeps)

	r.Observe(refA, ep, rateLimited(0))
	require.NoError(t, r.Wait(context.Background(), refA, ep))
	assert.Equal(t, []time.Duration{7 * time.Second, 10 * time.Second}, clock.sleeps)

	require.NoError(t, r.Wait(context.Background(), refB, ep))
	assert.Len(t, clock.sleeps, 2, "пауза кабинета A не касается B")
}

func TestWaitIsCanceledByContext(t *testing.T) {
	r, _, _ := newRegistry()
	r.Observe(refA, ep, rateLimited(time.Hour))

	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	assert.ErrorIs(t, r.Wait(ctx, refA, ep), context.Canceled)

	// Токен-бакет тоже уважает отмену: 0.1 запроса/с, второй токен — через 10 с.
	slow := New(Config{Fallback: EndpointLimit{Start: 0.1, Floor: 0.1, Ceiling: 0.1}}, time.Now, ozon.SleepContext, nil)
	require.NoError(t, slow.Wait(context.Background(), refA, "/x"))
	short, cancelShort := context.WithTimeout(context.Background(), 20*time.Millisecond)
	defer cancelShort()
	assert.Error(t, slow.Wait(short, refA, "/x"))
}

func TestConcurrentUseIsRaceFree(t *testing.T) {
	r := New(Config{Fallback: EndpointLimit{Start: 100000, Floor: 50000, Ceiling: 200000}, GrowAfter: 5, IncreaseStep: 1}, time.Now, ozon.SleepContext, nil)
	var wg sync.WaitGroup
	for i := range 16 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			ref := []account.Ref{refA, refB}[i%2]
			for j := range 50 {
				_ = r.Wait(context.Background(), ref, "/x")
				if j%10 == 0 {
					r.Observe(ref, "/x", rateLimited(time.Nanosecond))
				} else {
					r.Observe(ref, "/x", nil)
				}
				if j%25 == 0 {
					r.SetOverride(ref, "/x", 150000)
				}
			}
		}()
	}
	wg.Wait()
	assert.Positive(t, r.Rate(refA, "/x"))
}

func TestDefaultConfigMatchesApiDoc(t *testing.T) {
	cfg := DefaultConfig()
	assert.Equal(t, EndpointLimit{Start: 0.3, Floor: 0.05, Ceiling: 2, DefaultPause: 10 * time.Second}, cfg.Endpoints[ozon.EndpointAccrualByDay])
	for endpoint, l := range cfg.Endpoints {
		assert.LessOrEqual(t, l.Floor, l.Start, endpoint)
		assert.LessOrEqual(t, l.Start, l.Ceiling, endpoint)
		assert.Positive(t, l.DefaultPause, endpoint)
	}
}

type storms struct {
	mu   sync.Mutex
	list []Storm
}

func (s *storms) notify(st Storm) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.list = append(s.list, st)
}

func stormRegistry() (*Registry, *fakeClock, *storms) {
	r, clock, _ := newRegistry()
	st := &storms{}
	r.WithStormAlert(DefaultStormAlert(st.notify))
	return r, clock, st
}

// toFloor — довести пару до минимальной скорости (1 → 0.5 → 0.25 → 0.125 → 0.1).
func toFloor(r *Registry, ref account.Ref) {
	for range 4 {
		r.Observe(ref, ep, rateLimited(0))
	}
}

func TestStormIsSignalledOnThirdRateLimitAtFloor(t *testing.T) {
	r, clock, st := stormRegistry()
	toFloor(r, refA)
	require.InDelta(t, 0.1, r.Rate(refA, ep), 1e-9)
	assert.Empty(t, st.list, "пока скорость снижается — не шторм")

	r.Observe(refA, ep, rateLimited(0))
	_ = clock.Sleep(context.Background(), 10*time.Minute)
	r.Observe(refA, ep, rateLimited(0))
	assert.Empty(t, st.list, "два отказа на минимуме — ниже порога")

	r.Observe(refA, ep, rateLimited(0))
	require.Len(t, st.list, 1)
	assert.Equal(t, Storm{Account: refA, Endpoint: ep, Hits: 3, Window: time.Hour, Floor: 0.1}, st.list[0])
}

func TestStormSignalIsRateLimitedPerPair(t *testing.T) {
	r, clock, st := stormRegistry()
	toFloor(r, refA)
	for range 10 {
		r.Observe(refA, ep, rateLimited(0))
	}
	assert.Len(t, st.list, 1, "не чаще раза в Cooldown")

	_ = clock.Sleep(context.Background(), time.Hour)
	for range 3 {
		r.Observe(refA, ep, rateLimited(0))
	}
	assert.Len(t, st.list, 2, "через час — снова")
}

func TestOldFloorHitsExpireOutOfWindow(t *testing.T) {
	r, clock, st := stormRegistry()
	toFloor(r, refA)
	r.Observe(refA, ep, rateLimited(0))
	r.Observe(refA, ep, rateLimited(0))
	_ = clock.Sleep(context.Background(), 2*time.Hour)
	r.Observe(refA, ep, rateLimited(0))
	assert.Empty(t, st.list, "отказы за пределами окна не считаются")
}

func TestRecoveryResetsStormCounter(t *testing.T) {
	r, _, st := stormRegistry()
	toFloor(r, refA)
	r.Observe(refA, ep, rateLimited(0))
	r.Observe(refA, ep, rateLimited(0))
	for range 3 {
		r.Observe(refA, ep, nil) // рост: 0.1 → 0.6
	}
	r.Observe(refA, ep, rateLimited(0)) // уже не на минимуме — счётчик сброшен
	for r.Rate(refA, ep) > 0.1 {
		r.Observe(refA, ep, rateLimited(0)) // снова вниз, без отказов на минимуме
	}
	r.Observe(refA, ep, rateLimited(0))
	r.Observe(refA, ep, rateLimited(0))
	assert.Empty(t, st.list, "после восстановления считаются только новые отказы на минимуме")
	r.Observe(refA, ep, rateLimited(0))
	assert.Len(t, st.list, 1)
}

func TestStormIsIsolatedPerCabinetAndEndpoint(t *testing.T) {
	r, _, st := stormRegistry()
	toFloor(r, refA)
	for range 3 {
		r.Observe(refA, ep, rateLimited(0))
	}
	toFloor(r, refB)
	r.Observe(refB, ep, rateLimited(0))
	require.Len(t, st.list, 1)
	assert.Equal(t, refA, st.list[0].Account)
}

func TestNoStormAlertConfiguredIsSilent(t *testing.T) {
	r, _, _ := newRegistry()
	toFloor(r, refA)
	for range 10 {
		r.Observe(refA, ep, rateLimited(0))
	}
	assert.InDelta(t, 0.1, r.Rate(refA, ep), 1e-9)
}

func TestDefaultConfigCoversPostingLists(t *testing.T) {
	cfg := DefaultConfig()
	for _, endpoint := range []string{ozon.EndpointPostingFBOList, ozon.EndpointPostingFBSList, ozon.EndpointAccrualPostings} {
		limit, ok := cfg.Endpoints[endpoint]
		require.True(t, ok, endpoint)
		assert.LessOrEqual(t, limit.Start, 0.2, "ключ кабинета общий с Symfony — старт не выше 0,2 запроса/с")
		assert.Positive(t, limit.Floor)
		assert.Positive(t, limit.DefaultPause)
	}
}

func TestSharedQuotaRateLimitOnlyPauses(t *testing.T) {
	r, clock, st := stormRegistry()
	for range 10 {
		r.Observe(refA, ep3, rateLimited(0))
	}
	assert.InDelta(t, 0.2, r.Rate(refA, ep3), 1e-9, "чужая нагрузка не снижает темп")
	assert.Empty(t, st.list, "и не шторм")

	require.NoError(t, r.Wait(context.Background(), refA, ep3))
	assert.Equal(t, []time.Duration{5 * time.Second}, clock.sleeps, "пауза без Retry-After — дефолт endpoint'а")

	r.Observe(refA, ep3, rateLimited(time.Second))
	require.NoError(t, r.Wait(context.Background(), refA, ep3))
	assert.Equal(t, time.Second, clock.sleeps[len(clock.sleeps)-1], "Retry-After соблюдается")

	toFloor(r, refA)
	r.Observe(refA, ep, rateLimited(0))
	r.Observe(refA, ep, rateLimited(0))
	r.Observe(refA, ep, rateLimited(0))
	assert.Len(t, st.list, 1, "у остальных endpoint'ов шторм прежний")
}

func TestSharedQuotaSavedRateBelowFloorIsRaised(t *testing.T) {
	r, _, _ := newRegistry()
	r.Seed(refA, ep3, 0, 0.05)
	assert.InDelta(t, 0.2, r.Rate(refA, ep3), 1e-9, "темп, сохранённый до общей квоты, поднимается до пола")
}
