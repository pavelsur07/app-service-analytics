package ozonperf

import (
	"bytes"
	"context"
	"encoding/json"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
)

var mskZone = time.FixedZone("MSK", 3*60*60)

func TestOrderReportSendsMoscowDaysInUTC(t *testing.T) {
	var got map[string]any
	f := &fakePerf{ttl: 1800, handle: func(w http.ResponseWriter, r *http.Request, _ string) {
		assert.Equal(t, http.MethodPost, r.Method)
		assert.Equal(t, EndpointStatistics, r.URL.Path)
		_ = json.NewDecoder(r.Body).Decode(&got)
		_, _ = w.Write([]byte(`{"UUID":"8a3f-1","vendor":false}`))
	}}
	c, _, _ := newTestClient(t, f)
	day := time.Date(2026, 10, 17, 0, 0, 0, 0, mskZone)

	id, err := c.OrderReport(context.Background(), testRef, testCreds, []string{"3", "5"}, day, day)

	require.NoError(t, err)
	assert.Equal(t, "8a3f-1", id)
	assert.Equal(t, map[string]any{"campaigns": []any{"3", "5"}, "from": "2026-10-16T21:00:00Z", "to": "2026-10-17T20:59:59Z", "groupBy": "DATE"}, got)

	_, err = c.OrderReport(context.Background(), testRef, testCreds, make([]string, 11), day, day)
	require.Error(t, err, "больше 10 кампаний — без запроса")
}

func TestReportStatusStates(t *testing.T) {
	for state, want := range map[string]ReportState{"NOT_STARTED": ReportPending, "IN_PROGRESS": ReportPending, "OK": ReportReady, "ERROR": ReportFailed, "CANCELLED": ReportFailed} {
		f := &fakePerf{ttl: 1800, handle: func(w http.ResponseWriter, r *http.Request, _ string) {
			assert.Equal(t, "/api/client/statistics/u-1", r.URL.Path)
			_, _ = w.Write([]byte(`{"UUID":"u-1","state":"` + state + `","link":"/api/client/statistics/report?UUID=u-1"}`))
		}}
		c, _, logs := newTestClient(t, f)
		st, err := c.ReportStatus(context.Background(), testRef, testCreds, "u-1")
		require.NoError(t, err, state)
		assert.Equal(t, want, st.State, state)
		assert.Equal(t, "/api/client/statistics/report?UUID=u-1", st.Link)
		assert.Contains(t, logs.String(), `"endpoint":"/api/client/statistics/{UUID}"`, "пары лимитера и журнал — по шаблону")
	}
	f := &fakePerf{ttl: 1800, handle: func(w http.ResponseWriter, _ *http.Request, _ string) { _, _ = w.Write([]byte(`{"state":"WHATEVER"}`)) }}
	c, _, _ := newTestClient(t, f)
	_, err := c.ReportStatus(context.Background(), testRef, testCreds, "u-1")
	var apiErr *ozon.APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ozon.ClassInvalidResponse, apiErr.Class)
}

func TestDownloadReportFollowsLinkOnlyOnPerformanceHost(t *testing.T) {
	var paths []string
	f := &fakePerf{ttl: 1800, handle: func(w http.ResponseWriter, r *http.Request, auth string) {
		paths = append(paths, r.URL.Path+"?"+r.URL.RawQuery)
		assert.Contains(t, auth, "Bearer ")
		_, _ = w.Write([]byte("PK\x03\x04zip-body"))
	}}
	c, _, _ := newTestClient(t, f)

	p, err := c.DownloadReport(context.Background(), testRef, testCreds, "u-1", "/api/client/statistics/report?UUID=u-1")
	require.NoError(t, err)
	assert.Equal(t, "application/zip", p.ContentType)
	_, err = c.DownloadReport(context.Background(), testRef, testCreds, "u-2", "")
	require.NoError(t, err)
	assert.Equal(t, []string{"/api/client/statistics/report?UUID=u-1", "/api/client/statistics/report?UUID=u-2"}, paths)

	_, err = c.DownloadReport(context.Background(), testRef, testCreds, "u-3", "https://evil.example/report?UUID=u-3")
	var apiErr *ozon.APIError
	require.ErrorAs(t, err, &apiErr, "Bearer не уходит на чужой хост")
	assert.Len(t, paths, 2)
}

func TestReportStatusNotFoundIsFailure(t *testing.T) {
	f := &fakePerf{ttl: 1800, handle: func(w http.ResponseWriter, _ *http.Request, _ string) { w.WriteHeader(http.StatusNotFound) }}
	c, _, _ := newTestClient(t, f)
	st, err := c.ReportStatus(context.Background(), testRef, testCreds, "u-1")
	require.NoError(t, err)
	assert.Equal(t, ReportFailed, st.State, "пропавший отчёт — под правило «3 подряд»")
}

func TestDownloadRedirectToAnotherHostIsRefused(t *testing.T) {
	other := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Errorf("запрос ушёл на чужой хост, Authorization=%q", r.Header.Get("Authorization"))
	}))
	t.Cleanup(other.Close)
	f := &fakePerf{ttl: 1800, handle: func(w http.ResponseWriter, r *http.Request, _ string) {
		http.Redirect(w, r, other.URL+"/file.zip", http.StatusFound)
	}}
	c, _, _ := newTestClient(t, f)
	_, err := c.DownloadReport(context.Background(), testRef, testCreds, "u-1", "")
	require.Error(t, err)
}

func TestActiveCampaignsNeedKnownNumericMetrics(t *testing.T) {
	active, ok := ActiveCampaigns([]byte(`{"rows":[{"id":"3","views":"10","clicks":"0","moneySpent":"0"},{"id":4,"views":0,"clicks":0,"moneySpent":"0,00"}]}`))
	require.True(t, ok)
	assert.Equal(t, map[string]bool{"3": true}, active)

	for name, body := range map[string]string{
		"renamed":    `{"rows":[{"id":"3","renamedViews":"10"}]}`,
		"not number": `{"rows":[{"id":"3","views":"много"}]}`,
		"no id":      `{"rows":[{"views":"10"}]}`,
		"no rows":    `{"result":{}}`,
	} {
		_, ok := ActiveCampaigns([]byte(body))
		assert.False(t, ok, name)
	}
	empty, ok := ActiveCampaigns([]byte(`{"rows":[]}`))
	assert.True(t, ok, "пустой день — известная форма")
	assert.Empty(t, empty)
}

func TestRedirectSchemeDowngradeIsRefused(t *testing.T) {
	c := NewClient("https://api-performance.ozon.ru", &http.Client{}, nil, time.Now)
	req, _ := http.NewRequestWithContext(context.Background(), http.MethodGet, "http://api-performance.ozon.ru/file.zip", nil)
	assert.Error(t, c.http.CheckRedirect(req, nil), "https → http — Bearer открытым текстом")
	same, _ := http.NewRequestWithContext(context.Background(), http.MethodGet, "https://api-performance.ozon.ru/file.zip", nil)
	assert.NoError(t, c.http.CheckRedirect(same, nil))
}

func TestTokenNotFoundIsNotReportFailure(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { w.WriteHeader(http.StatusNotFound) }))
	t.Cleanup(srv.Close)
	c := NewClient(srv.URL, srv.Client(), slog.New(slog.NewJSONHandler(&bytes.Buffer{}, nil)), time.Now)

	_, err := c.ReportStatus(context.Background(), testRef, testCreds, "u-1")

	var apiErr *ozon.APIError
	require.ErrorAs(t, err, &apiErr, "404 токена — не «отчёт пропал»")
	assert.Equal(t, EndpointToken, apiErr.Endpoint)
}
