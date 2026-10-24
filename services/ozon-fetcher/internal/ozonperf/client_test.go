package ozonperf

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account/accounttest"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
)

var testCreds = Credentials{ClientID: "perf-1", ClientSecret: secret}

var testRef = accounttest.Ref("conn-1")

// fakePerf — токен и методы рекламы; tokens — число выданных токенов.
type fakePerf struct {
	tokens   atomic.Int32
	ttl      int
	tokenErr int // статус ответа токена, 0 — успех
	// handle — ответ метода по выданному токену.
	handle func(w http.ResponseWriter, r *http.Request, token string)
}

func (f *fakePerf) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path == EndpointToken {
		var body map[string]string
		_ = json.NewDecoder(r.Body).Decode(&body)
		if body["client_id"] != testCreds.ClientID || body["client_secret"] != secret || body["grant_type"] != "client_credentials" {
			w.WriteHeader(http.StatusBadRequest)
			return
		}
		if f.tokenErr != 0 {
			w.WriteHeader(f.tokenErr)
			_, _ = w.Write([]byte(`{"error":"x"}`))
			return
		}
		n := f.tokens.Add(1)
		_ = json.NewEncoder(w).Encode(map[string]any{"access_token": "tok-" + string(rune('0'+n)), "expires_in": f.ttl, "token_type": "Bearer"})
		return
	}
	auth := r.Header.Get("Authorization")
	f.handle(w, r, auth)
}

type clock struct {
	mu  sync.Mutex
	now time.Time
}

func (c *clock) Now() time.Time      { c.mu.Lock(); defer c.mu.Unlock(); return c.now }
func (c *clock) Add(d time.Duration) { c.mu.Lock(); c.now = c.now.Add(d); c.mu.Unlock() }

func newTestClient(t *testing.T, f *fakePerf) (*Client, *clock, *bytes.Buffer) {
	t.Helper()
	srv := httptest.NewServer(f)
	t.Cleanup(srv.Close)
	clk := &clock{now: time.Date(2026, 10, 22, 1, 0, 0, 0, time.UTC)}
	logs := &bytes.Buffer{}
	return NewClient(srv.URL, srv.Client(), slog.New(slog.NewJSONHandler(logs, nil)), clk.Now), clk, logs
}

func campaignsOK(w http.ResponseWriter, _ *http.Request, _ string) {
	_, _ = w.Write([]byte(`{"list":[{"id":"1","title":"A","state":"CAMPAIGN_STATE_RUNNING","advObjectType":"SKU"}],"total":"1"}`))
}

func TestTokenIsSharedAndRefreshedBeforeExpiry(t *testing.T) {
	f := &fakePerf{ttl: 1800, handle: func(w http.ResponseWriter, r *http.Request, auth string) {
		assert.Contains(t, auth, "Bearer tok-")
		campaignsOK(w, r, auth)
	}}
	c, clk, logs := newTestClient(t, f)

	var wg sync.WaitGroup
	for range 5 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			_, err := c.FetchCampaigns(context.Background(), testRef, testCreds)
			assert.NoError(t, err)
		}()
	}
	wg.Wait()
	assert.Equal(t, int32(1), f.tokens.Load(), "параллельные задачи — один запрос токена")

	clk.Add(1800*time.Second - tokenSafetyMargin - time.Second)
	_, err := c.FetchCampaigns(context.Background(), testRef, testCreds)
	require.NoError(t, err)
	assert.Equal(t, int32(1), f.tokens.Load(), "до запаса срока — тот же токен")

	clk.Add(2 * time.Second)
	_, err = c.FetchCampaigns(context.Background(), testRef, testCreds)
	require.NoError(t, err)
	assert.Equal(t, int32(2), f.tokens.Load(), "за 5 мин до истечения — новый токен")

	assert.NotContains(t, logs.String(), secret)
	assert.NotContains(t, logs.String(), "tok-")
}

func TestUnauthorizedRefreshesTokenOnce(t *testing.T) {
	var calls atomic.Int32
	f := &fakePerf{ttl: 1800, handle: func(w http.ResponseWriter, r *http.Request, auth string) {
		calls.Add(1)
		if auth == "Bearer tok-1" {
			w.WriteHeader(http.StatusUnauthorized)
			return
		}
		campaignsOK(w, r, auth)
	}}
	c, _, _ := newTestClient(t, f)

	_, err := c.FetchCampaigns(context.Background(), testRef, testCreds)
	require.NoError(t, err)
	assert.Equal(t, int32(2), f.tokens.Load())
	assert.Equal(t, int32(2), calls.Load())

	always := &fakePerf{ttl: 1800, handle: func(w http.ResponseWriter, _ *http.Request, _ string) { w.WriteHeader(http.StatusUnauthorized) }}
	c2, _, _ := newTestClient(t, always)
	_, err = c2.FetchCampaigns(context.Background(), testRef, testCreds)
	var apiErr *ozon.APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ozon.ClassAuth, apiErr.Class)
	assert.Equal(t, int32(2), always.tokens.Load(), "повтор — один")
}

func TestTokenErrorsAreClassified(t *testing.T) {
	for status, class := range map[int]ozon.Class{
		http.StatusBadRequest:         ozon.ClassAuth,
		http.StatusUnauthorized:       ozon.ClassAuth,
		http.StatusForbidden:          ozon.ClassAuth,
		http.StatusTooManyRequests:    ozon.ClassRateLimit,
		http.StatusServiceUnavailable: ozon.ClassTransient,
	} {
		f := &fakePerf{ttl: 1800, tokenErr: status, handle: campaignsOK}
		c, _, logs := newTestClient(t, f)
		_, err := c.FetchCampaigns(context.Background(), testRef, testCreds)
		var apiErr *ozon.APIError
		require.ErrorAs(t, err, &apiErr, status)
		assert.Equal(t, class, apiErr.Class, status)
		assert.NotContains(t, logs.String(), secret)
		assert.NotContains(t, err.Error(), secret)
	}
}

func TestDayMethodsSendSingleDayAndCheckJSON(t *testing.T) {
	var queries []string
	f := &fakePerf{ttl: 1800, handle: func(w http.ResponseWriter, r *http.Request, _ string) {
		assert.Equal(t, http.MethodGet, r.Method)
		queries = append(queries, r.URL.Path+"?"+r.URL.RawQuery)
		switch r.URL.Path {
		case EndpointExpense:
			_, _ = w.Write([]byte(`{"rows":[]}`))
		case EndpointDaily:
			_, _ = w.Write([]byte(`<html>oops</html>`))
		}
	}}
	c, _, logs := newTestClient(t, f)

	p, err := c.FetchExpense(context.Background(), testRef, testCreds, "2026-10-21")
	require.NoError(t, err)
	assert.JSONEq(t, `{"rows":[]}`, string(p.Body))
	assert.Equal(t, 1, p.No)

	_, err = c.FetchDaily(context.Background(), testRef, testCreds, "2026-10-21")
	var apiErr *ozon.APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ozon.ClassInvalidResponse, apiErr.Class)

	assert.Equal(t, []string{
		EndpointExpense + "?dateFrom=2026-10-21&dateTo=2026-10-21",
		EndpointDaily + "?dateFrom=2026-10-21&dateTo=2026-10-21",
	}, queries)
	assert.NotContains(t, logs.String(), "dateFrom", "query не в журнале")
}

func TestCampaignsRequireList(t *testing.T) {
	for name, body := range map[string]string{"no list": `{"total":"0"}`, "not json": `x`, "list object": `{"list":{}}`} {
		f := &fakePerf{ttl: 1800, handle: func(w http.ResponseWriter, _ *http.Request, _ string) { _, _ = w.Write([]byte(body)) }}
		c, _, _ := newTestClient(t, f)
		_, err := c.FetchCampaigns(context.Background(), testRef, testCreds)
		var apiErr *ozon.APIError
		require.ErrorAs(t, err, &apiErr, name)
		assert.Equal(t, ozon.ClassInvalidResponse, apiErr.Class, name)
	}
}

func TestRateLimitCarriesRetryAfter(t *testing.T) {
	f := &fakePerf{ttl: 1800, handle: func(w http.ResponseWriter, _ *http.Request, _ string) {
		w.Header().Set("Retry-After", "7")
		w.WriteHeader(http.StatusTooManyRequests)
	}}
	c, _, _ := newTestClient(t, f)
	_, err := c.FetchCampaigns(context.Background(), testRef, testCreds)
	var apiErr *ozon.APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ozon.ClassRateLimit, apiErr.Class)
	assert.Equal(t, 7*time.Second, apiErr.RetryAfter)
}

func TestErrorMessagesNeverCarrySecretOrToken(t *testing.T) {
	echo := &fakePerf{ttl: 1800, handle: func(w http.ResponseWriter, _ *http.Request, auth string) {
		w.WriteHeader(http.StatusBadRequest)
		_ = json.NewEncoder(w).Encode(map[string]any{"code": 3, "message": "bad request with " + auth + " and " + secret})
	}}
	c, _, logs := newTestClient(t, echo)
	_, err := c.FetchCampaigns(context.Background(), testRef, testCreds)
	require.Error(t, err)
	assert.NotContains(t, err.Error(), secret)
	assert.NotContains(t, err.Error(), "tok-1")
	assert.Contains(t, err.Error(), "bad request with", "текст Ozon остаётся")
	assert.NotContains(t, logs.String(), secret)

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
		_ = json.NewEncoder(w).Encode(map[string]any{"code": 16, "message": "invalid client_secret " + secret})
	}))
	t.Cleanup(srv.Close)
	c2 := NewClient(srv.URL, srv.Client(), slog.New(slog.NewJSONHandler(&bytes.Buffer{}, nil)), time.Now)
	_, err = c2.FetchCampaigns(context.Background(), testRef, testCreds)
	require.Error(t, err)
	assert.NotContains(t, err.Error(), secret)
}

func TestTokenNeverFormatsValue(t *testing.T) {
	tok := token{value: "tok-secret", expires: time.Now()}
	for _, s := range []string{fmt.Sprint(tok), fmt.Sprintf("%v %+v %#v", tok, tok, tok)} {
		assert.NotContains(t, s, "tok-secret")
	}
}

func TestShortTokenIsNotCachedPastExpiry(t *testing.T) {
	f := &fakePerf{ttl: 30, handle: campaignsOK}
	c, clk, _ := newTestClient(t, f)
	_, err := c.FetchCampaigns(context.Background(), testRef, testCreds)
	require.NoError(t, err)
	clk.Add(25 * time.Second)
	_, err = c.FetchCampaigns(context.Background(), testRef, testCreds)
	require.NoError(t, err)
	assert.Equal(t, int32(2), f.tokens.Load(), "токен на 30 с не живёт в кэше 25 с")
}

func TestSecretCrossingMessageLimitIsRedacted(t *testing.T) {
	// Токен и секрет начинаются за 3–4 символа до границы обрезки текста (200).
	echo := &fakePerf{ttl: 1800, handle: func(w http.ResponseWriter, _ *http.Request, auth string) {
		w.WriteHeader(http.StatusBadRequest)
		_ = json.NewEncoder(w).Encode(map[string]any{"code": 3, "message": strings.Repeat("x", 190) + auth})
	}}
	c, _, _ := newTestClient(t, echo)
	_, err := c.FetchCampaigns(context.Background(), testRef, testCreds)
	require.Error(t, err)
	assert.NotContains(t, err.Error(), "Bearer tok", "и префикс токена")

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
		_ = json.NewEncoder(w).Encode(map[string]any{"code": 16, "message": strings.Repeat("x", 196) + secret})
	}))
	t.Cleanup(srv.Close)
	c2 := NewClient(srv.URL, srv.Client(), slog.New(slog.NewJSONHandler(&bytes.Buffer{}, nil)), time.Now)
	_, err = c2.FetchCampaigns(context.Background(), testRef, testCreds)
	require.Error(t, err)
	assert.NotContains(t, err.Error(), "xperf", "и префикс секрета")
}

func TestJSONEscapedSecretIsRedacted(t *testing.T) {
	escaped := `\u0070` + secret[1:] // «p» секрета — JSON-экранированием
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
		_, _ = w.Write([]byte(`{"code":16,"message":"invalid client_secret ` + escaped + `"}`))
	}))
	t.Cleanup(srv.Close)
	c := NewClient(srv.URL, srv.Client(), slog.New(slog.NewJSONHandler(&bytes.Buffer{}, nil)), time.Now)
	_, err := c.FetchCampaigns(context.Background(), testRef, testCreds)
	require.Error(t, err)
	assert.NotContains(t, err.Error(), secret)
	assert.Contains(t, err.Error(), "invalid client_secret [redacted]")
}

func TestShapeListsKeysWithoutValues(t *testing.T) {
	assert.Equal(t, `{list,total} list=[2]{advObjectType,id,state,title}`,
		Shape([]byte(`{"list":[{"id":"1","title":"Secret title","state":"X","advObjectType":"SKU"},{"id":"2"}],"total":"2"}`)))
	assert.Equal(t, `{rows} rows=[]`, Shape([]byte(`{"rows":[]}`)))
	assert.Equal(t, `[1]{date,moneySpent}`, Shape([]byte(`[{"date":"2026-10-21","moneySpent":"123,45"}]`)))
	assert.NotContains(t, Shape([]byte(`{"list":[{"title":"Secret title"}]}`)), "Secret")
	assert.Equal(t, "not json", Shape([]byte(`x`)))
}
