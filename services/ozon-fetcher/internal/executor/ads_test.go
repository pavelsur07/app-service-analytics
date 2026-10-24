package executor

import (
	"context"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account/accounttest"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozonperf"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

const perfSecret = "perf-secret-xyz"

type fakePerf struct {
	creds   []ozonperf.Credentials
	days    []string
	err     error
	reports *reportFake
}

func (f *fakePerf) FetchCampaigns(_ context.Context, _ account.Ref, creds ozonperf.Credentials) (ozon.Page, error) {
	f.creds = append(f.creds, creds)
	if f.err != nil {
		return ozon.Page{}, f.err
	}
	return ozon.Page{No: 1, Body: []byte(`{"list":[]}`), ContentType: "application/json"}, nil
}

func (f *fakePerf) FetchExpense(_ context.Context, _ account.Ref, creds ozonperf.Credentials, day string) (ozon.Page, error) {
	f.creds = append(f.creds, creds)
	f.days = append(f.days, "expense:"+day)
	if f.err != nil {
		return ozon.Page{}, f.err
	}
	return ozon.Page{No: 1, Body: []byte(`{"rows":[]}`), ContentType: "application/json"}, nil
}

func (f *fakePerf) FetchDaily(_ context.Context, _ account.Ref, creds ozonperf.Credentials, day string) (ozon.Page, error) {
	f.creds = append(f.creds, creds)
	f.days = append(f.days, "daily:"+day)
	if f.err != nil {
		return ozon.Page{}, f.err
	}
	return ozon.Page{No: 1, Body: []byte(`{"rows":[]}`), ContentType: "application/json"}, nil
}

var adsCabinet = connections.Cabinet{Account: accounttest.Ref("ref-a"),
	Performance:   &ozonperf.Credentials{ClientID: "perf-1", ClientSecret: perfSecret},
	ResourceTypes: []string{ResourceAdsCampaigns, ResourceAdsExpense, ResourceAdsDaily}}

func adsHarness(p *fakePerf) *harness {
	h := newHarness(&fakeOzon{})
	h.exec = h.exec.WithPerformance(p)
	return h
}

func TestAdsResourcesLoadOnePageWithPerformanceKey(t *testing.T) {
	p := &fakePerf{}
	h := adsHarness(p)

	for _, r := range []string{ResourceAdsCampaigns, ResourceAdsExpense, ResourceAdsDaily} {
		h.tasks.finished = nil
		res := h.exec.Run(context.Background(), task(r, today), adsCabinet, "trace")
		assert.Equal(t, "done", res.Outcome, r)
	}
	assert.Equal(t, []string{"expense:" + today, "daily:" + today}, p.days, "день = period_key")
	require.Len(t, p.creds, 3)
	assert.Equal(t, perfSecret, p.creds[0].ClientSecret)
	assert.Empty(t, h.tasks.resumed, "успех рекламы не сбрасывает счётчик отказов seller")
	assert.NotContains(t, h.logs.String(), perfSecret)
	assert.Equal(t, ResourceAdsExpense, h.raw.puts[1].ResourceType)
}

func TestAdsTaskIsNotRunWithAnotherConnectionsKey(t *testing.T) {
	p := &fakePerf{}
	h := adsHarness(p)
	other := adsCabinet
	other.Account = accounttest.Ref("ref-b")

	res := h.exec.Run(context.Background(), task(ResourceAdsCampaigns, today), other, "trace")

	assert.Equal(t, "no_credentials", res.Outcome)
	assert.Empty(t, p.creds, "запросов с ключом Performance другого подключения нет")
	assert.Empty(t, h.raw.puts)
}

func TestAdsWithoutKeyOrResourceDoNotCallOzon(t *testing.T) {
	p := &fakePerf{}
	h := adsHarness(p)

	res := h.exec.Run(context.Background(), task(ResourceAdsExpense, today), cabinet, "trace")
	assert.Equal(t, "no_credentials", res.Outcome, "кабинет без ключа Performance")

	off := adsCabinet
	off.ResourceTypes = nil
	res = h.exec.Run(context.Background(), task(ResourceAdsExpense, today), off, "trace")
	assert.Equal(t, "resource_disabled", res.Outcome)
	assert.Empty(t, p.creds)
}

func TestAdsCampaignsOfPassedDayAreMissed(t *testing.T) {
	p := &fakePerf{}
	h := adsHarness(p)

	res := h.exec.Run(context.Background(), task(ResourceAdsCampaigns, "2026-10-16"), adsCabinet, "trace")

	assert.Equal(t, "snapshot_missed", res.Outcome)
	assert.Empty(t, p.creds)
	res = h.exec.Run(context.Background(), task(ResourceAdsExpense, "2026-10-16"), adsCabinet, "trace")
	assert.Equal(t, "done", res.Outcome, "расход прошлого дня — обычная загрузка")
}

func TestAdsAuthPausesOnlyTheTask(t *testing.T) {
	p := &fakePerf{err: &ozon.APIError{Class: ozon.ClassAuth, Endpoint: ozonperf.EndpointExpense, Status: 401}}
	h := adsHarness(p)

	res := h.exec.Run(context.Background(), task(ResourceAdsExpense, today), adsCabinet, "trace")

	assert.Equal(t, "auth", res.Outcome)
	assert.Equal(t, store.StatusFailedRetryable, h.tasks.finished.Status)
	assert.True(t, h.tasks.finished.NextRunAt.Equal(t0.Add(DefaultConfig().AuthRetryPause)))
	assert.Empty(t, h.tasks.pauses, "кабинет (seller) не приостанавливается")
	assert.Equal(t, 1, h.refresher.calls, "снимок кабинетов — за новым ключом")
	assert.NotContains(t, h.logs.String(), `"level":"ERROR"`)

	again := task(ResourceAdsExpense, today)
	again.LastErrorClass = string(ozon.ClassAuth)
	res = h.exec.Run(context.Background(), again, adsCabinet, "trace")
	assert.Equal(t, "auth", res.Outcome)
	assert.True(t, h.tasks.finished.NextRunAt.Equal(t0.Add(DefaultConfig().AuthSuspend)))
	assert.Contains(t, h.logs.String(), `"level":"ERROR"`)
	assert.Empty(t, h.tasks.pauses)
	assert.NotContains(t, h.logs.String(), perfSecret)
}

func TestAdsRateLimitKeepsTaskPending(t *testing.T) {
	p := &fakePerf{err: &ozon.APIError{Class: ozon.ClassRateLimit, Endpoint: ozonperf.EndpointDaily, Status: 429, RetryAfter: 3 * time.Second}}
	h := adsHarness(p)

	res := h.exec.Run(context.Background(), task(ResourceAdsDaily, today), adsCabinet, "trace")

	assert.Equal(t, "rate_limit", res.Outcome)
	assert.True(t, h.tasks.finished.NextRunAt.Equal(t0.Add(3*time.Second)))
}
