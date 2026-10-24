package httpapi

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account/accounttest"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

const token = "status-token-0123456789abcdef0123" //nolint:gosec // G101: фиктивный токен теста

var t0 = time.Date(2026, 10, 17, 9, 0, 0, 0, time.UTC)

func msk(t *testing.T) *time.Location {
	t.Helper()
	loc, err := time.LoadLocation("Europe/Moscow")
	require.NoError(t, err)
	return loc
}

func do(t *testing.T, h http.Handler, path, auth string) (int, map[string]any) {
	t.Helper()
	req := httptest.NewRequestWithContext(context.Background(), http.MethodGet, path, nil)
	if auth != "" {
		req.Header.Set("Authorization", auth)
	}
	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, req)
	var body map[string]any
	require.NoError(t, json.Unmarshal(rec.Body.Bytes(), &body))
	return rec.Code, body
}

func deps(t *testing.T) Deps {
	return Deps{Token: token, Now: func() time.Time { return t0 }, Location: msk(t), Logger: slog.New(slog.NewTextHandler(io.Discard, nil))}
}

func TestFetchStatusRequiresToken(t *testing.T) {
	d := deps(t)
	d.Status = func(context.Context, time.Time, string) (store.StatusReport, error) { return store.StatusReport{}, nil }
	h := New(d)

	for _, auth := range []string{"", "Bearer wrong", token, "Basic " + token} {
		code, body := do(t, h, "/internal/fetch-status", auth)
		assert.Equal(t, http.StatusUnauthorized, code, auth)
		assert.Equal(t, "internal_auth_required", body["error"].(map[string]any)["code"])
	}

	empty := deps(t)
	empty.Token = ""
	code, _ := do(t, New(empty), "/internal/fetch-status", "Bearer ")
	assert.Equal(t, http.StatusUnauthorized, code, "без настроенного токена — закрыто")
}

func TestFetchStatusReturnsReportForMoscowWindow(t *testing.T) {
	d := deps(t)
	var gotWindow string
	d.Status = func(_ context.Context, now time.Time, windowFrom string) (store.StatusReport, error) {
		gotWindow = windowFrom
		return store.StatusReport{Cabinets: []store.CabinetStatus{{CompanyID: accounttest.Ref("ref-a").CompanyID, AccountID: accounttest.Ref("ref-a").AccountID, Outcomes24h: map[string]int{"done": 3}}}, StoppedResources: []string{}}, nil
	}

	code, body := do(t, New(d), "/internal/fetch-status", "Bearer "+token)

	assert.Equal(t, http.StatusOK, code)
	assert.Equal(t, "2026-08-01", gotWindow, "окно M-2…M по МСК")
	report := body["report"].(map[string]any)
	cab := report["cabinets"].([]any)[0].(map[string]any)
	assert.Equal(t, accounttest.Ref("ref-a").AccountID, cab["marketplace_account_id"])
	assert.Equal(t, accounttest.Ref("ref-a").CompanyID, cab["company_id"])
	assert.Equal(t, "2026-08-01", body["window_from"])
}

func TestFetchStatusErrorsAndDisabled(t *testing.T) {
	d := deps(t)
	code, body := do(t, New(d), "/internal/fetch-status", "Bearer "+token)
	assert.Equal(t, http.StatusServiceUnavailable, code)
	assert.Equal(t, "ingestion_disabled", body["error"].(map[string]any)["code"])

	d.Status = func(context.Context, time.Time, string) (store.StatusReport, error) {
		return store.StatusReport{}, errors.New("db down")
	}
	code, body = do(t, New(d), "/internal/fetch-status", "Bearer "+token)
	assert.Equal(t, http.StatusServiceUnavailable, code)
	assert.NotContains(t, body["error"].(map[string]any)["message"], "db down", "внутренняя ошибка не отдаётся наружу")
}

func TestReady(t *testing.T) {
	ok := func(context.Context) error { return nil }
	fail := func(context.Context) error { return errors.New("down") }
	tests := []struct {
		name     string
		db, s3   func(context.Context) error
		cabinets func() bool
		want     int
		check    map[string]string
	}{
		{name: "scaffold", want: 200, check: map[string]string{"db": "disabled", "s3": "disabled"}},
		{name: "all up", db: ok, s3: ok, cabinets: func() bool { return true }, want: 200, check: map[string]string{"db": "up", "s3": "up", "cabinets": "up"}},
		{name: "db down", db: fail, want: 503, check: map[string]string{"db": "down", "status": "not ready"}},
		{name: "s3 down", db: ok, s3: fail, want: 503, check: map[string]string{"s3": "down"}},
		{name: "waiting for cabinets", db: ok, s3: ok, cabinets: func() bool { return false }, want: 503, check: map[string]string{"cabinets": "waiting"}},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			d := deps(t)
			d.DBPing, d.S3Ping, d.CabinetsReady = tt.db, tt.s3, tt.cabinets
			code, body := do(t, New(d), "/ready", "")
			assert.Equal(t, tt.want, code)
			for k, v := range tt.check {
				assert.Equal(t, v, body[k], k)
			}
		})
	}
}

func TestS3PingIsCached(t *testing.T) {
	calls := 0
	now := t0
	d := deps(t)
	d.Now = func() time.Time { return now }
	d.S3Ping = func(context.Context) error { calls++; return nil }
	h := New(d)

	do(t, h, "/ready", "")
	do(t, h, "/ready", "")
	assert.Equal(t, 1, calls)
	now = now.Add(S3PingCache)
	do(t, h, "/ready", "")
	assert.Equal(t, 2, calls)
}

func TestHealth(t *testing.T) {
	code, body := do(t, New(deps(t)), "/health", "")
	assert.Equal(t, http.StatusOK, code)
	assert.Equal(t, "ok", body["status"])
}
