package ozon

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account/accounttest"
)

const (
	testClientID = "client-123"
	testAPIKey   = "secret-api-key-value" //nolint:gosec // G101: фиктивный ключ теста
)

var testCreds = Credentials{ClientID: testClientID, APIKey: testAPIKey}

var testRef = accounttest.Ref("conn-1")

func newTestClient(t *testing.T, handler http.HandlerFunc, timeout time.Duration) (*Client, *bytes.Buffer) {
	t.Helper()
	srv := httptest.NewServer(handler)
	t.Cleanup(srv.Close)
	logs := &bytes.Buffer{}
	logger := slog.New(slog.NewJSONHandler(logs, nil))
	return NewClient(srv.URL, &http.Client{Timeout: timeout}, logger, time.Now), logs
}

func TestPostSendsCredentialsAndReturnsRawBody(t *testing.T) {
	const body = `{"accruals":[],"last_id":""}`
	client, logs := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		assert.Equal(t, http.MethodPost, r.Method)
		assert.Equal(t, "/v1/finance/accrual/by-day", r.URL.Path)
		assert.Equal(t, testClientID, r.Header.Get("Client-Id"))
		assert.Equal(t, testAPIKey, r.Header.Get("Api-Key"))
		assert.Equal(t, "application/json", r.Header.Get("Content-Type"))
		got, _ := io.ReadAll(r.Body)
		assert.JSONEq(t, `{"date":"2026-10-15"}`, string(got))
		_, _ = io.WriteString(w, body)
	}, time.Second)

	resp, err := client.Post(context.Background(), testRef, testCreds, "/v1/finance/accrual/by-day", map[string]string{"date": "2026-10-15"})

	require.NoError(t, err)
	assert.Equal(t, 200, resp.Status)
	assert.Equal(t, body, string(resp.Body))
	assert.Contains(t, logs.String(), `"outcome":"ok"`)
	assert.Contains(t, logs.String(), `"marketplace_account_id":"`+testRef.AccountID+`"`)
	assert.NotContains(t, logs.String(), testAPIKey)
	assert.NotContains(t, logs.String(), "accruals")
}

func TestPostClassifiesErrors(t *testing.T) {
	tests := []struct {
		name   string
		status int
		body   string
		want   Class
	}{
		{"rate limit", 429, `{"code":8,"message":"too many"}`, ClassRateLimit},
		{"server", 503, ``, ClassTransient},
		{"auth", 401, `{"code":16}`, ClassAuth},
		{"obsolete", 400, `{"code":9,"message":"obsolete method cannot be used"}`, ClassObsoleteEndpoint},
		{"invalid", 422, `{"code":3}`, ClassInvalidRequest},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			client, logs := newTestClient(t, func(w http.ResponseWriter, _ *http.Request) {
				w.WriteHeader(tt.status)
				_, _ = io.WriteString(w, tt.body)
			}, time.Second)

			_, err := client.Post(context.Background(), testRef, testCreds, "/v1/x", struct{}{})

			var apiErr *APIError
			require.ErrorAs(t, err, &apiErr)
			assert.Equal(t, tt.want, apiErr.Class)
			assert.Contains(t, logs.String(), fmt.Sprintf(`"outcome":"%s"`, tt.want))
			assert.NotContains(t, logs.String(), testAPIKey)
		})
	}
}

// Ответ, повторяющий присланный ключ, не выносит его в текст ошибки —
// в том числе на границе обрезки сообщения.
func TestErrorMessageNeverCarriesAPIKey(t *testing.T) {
	for name, message := range map[string]string{
		"echo":        "invalid Api-Key " + testAPIKey,
		"at the edge": strings.Repeat("x", messageLimit-5) + testAPIKey,
	} {
		t.Run(name, func(t *testing.T) {
			body, err := json.Marshal(map[string]any{"code": 16, "message": message})
			require.NoError(t, err)
			client, _ := newTestClient(t, func(w http.ResponseWriter, _ *http.Request) {
				w.WriteHeader(http.StatusForbidden)
				_, _ = w.Write(body)
			}, time.Second)

			_, err = client.Post(context.Background(), testRef, testCreds, "/v1/x", struct{}{})

			require.Error(t, err)
			assert.NotContains(t, err.Error(), testAPIKey)
			assert.NotContains(t, err.Error(), testAPIKey[:5])
		})
	}
}

// Скачивание файла отчёта (без ключа) следует редиректу, как в источнике;
// запрос с ключом — нет.
func TestRedirectFollowedOnlyWithoutKey(t *testing.T) {
	client, _ := newTestClient(t, func(http.ResponseWriter, *http.Request) {}, time.Second)
	file, _ := http.NewRequestWithContext(context.Background(), http.MethodGet, "https://storage.example/file.csv", nil)
	next, _ := http.NewRequestWithContext(context.Background(), http.MethodGet, "https://cdn.example/file.csv", nil)
	assert.NoError(t, client.http.CheckRedirect(next, []*http.Request{file}))

	keyed, _ := http.NewRequestWithContext(context.Background(), http.MethodPost, "https://api-seller.ozon.ru/v1/x", nil)
	keyed.Header.Set("Api-Key", testAPIKey)
	assert.ErrorIs(t, client.http.CheckRedirect(next, []*http.Request{keyed}), http.ErrUseLastResponse)
}

// Редирект не выполняется: ключ кабинета не уходит на другой хост.
func TestRedirectIsNotFollowed(t *testing.T) {
	var leaked atomic.Bool
	other := httptest.NewServer(http.HandlerFunc(func(_ http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Api-Key") != "" {
			leaked.Store(true)
		}
	}))
	t.Cleanup(other.Close)
	client, _ := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, other.URL+"/v1/x", http.StatusTemporaryRedirect)
	}, time.Second)

	_, err := client.Post(context.Background(), testRef, testCreds, "/v1/x", struct{}{})

	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, http.StatusTemporaryRedirect, apiErr.Status)
	assert.False(t, leaked.Load(), "запрос с ключом на другой хост не ушёл")
}

func TestPostTimeoutIsTransient(t *testing.T) {
	release := make(chan struct{})
	client, _ := newTestClient(t, func(_ http.ResponseWriter, _ *http.Request) {
		<-release
	}, 50*time.Millisecond)
	defer close(release)

	_, err := client.Post(context.Background(), testRef, testCreds, "/v1/x", struct{}{})

	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ClassTransient, apiErr.Class)
	assert.Equal(t, 0, apiErr.Status)
}

func TestPostCanceledContextIsNotAnAPIError(t *testing.T) {
	release := make(chan struct{})
	client, logs := newTestClient(t, func(_ http.ResponseWriter, _ *http.Request) {
		<-release
	}, 5*time.Second)
	defer close(release)

	ctx, cancel := context.WithCancel(context.Background())
	time.AfterFunc(30*time.Millisecond, cancel)

	_, err := client.Post(ctx, testRef, testCreds, "/v1/x", struct{}{})

	require.ErrorIs(t, err, context.Canceled)
	var apiErr *APIError
	assert.False(t, errors.As(err, &apiErr))
	assert.Contains(t, logs.String(), `"outcome":"canceled"`)
}

func TestPostRejectsOversizedBody(t *testing.T) {
	client, _ := newTestClient(t, func(w http.ResponseWriter, _ *http.Request) {
		_, _ = io.Copy(w, io.LimitReader(zeroReader{}, MaxBodyBytes+1))
	}, 30*time.Second)

	_, err := client.Post(context.Background(), testRef, testCreds, "/v1/x", struct{}{})

	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ClassInvalidResponse, apiErr.Class)
	assert.ErrorIs(t, err, ErrBodyTooLarge)
}

func TestCredentialsNeverPrintTheKey(t *testing.T) {
	logs := &bytes.Buffer{}
	logger := slog.New(slog.NewJSONHandler(logs, nil))
	logger.Info("x", slog.Any("creds", testCreds))
	logger.Info("nested", slog.Any("holder", struct{ Creds *Credentials }{&testCreds}))
	asJSON, err := json.Marshal(struct{ Creds Credentials }{testCreds})
	require.NoError(t, err)

	for _, s := range []string{
		fmt.Sprint(testCreds), fmt.Sprintf("%v", testCreds), fmt.Sprintf("%+v", testCreds),
		fmt.Sprintf("%#v", testCreds), testCreds.String(), logs.String(), string(asJSON),
	} {
		assert.NotContains(t, s, testAPIKey)
		assert.True(t, strings.Contains(s, testClientID), s)
	}
}

type zeroReader struct{}

func (zeroReader) Read(p []byte) (int, error) {
	for i := range p {
		p[i] = '0'
	}
	return len(p), nil
}
