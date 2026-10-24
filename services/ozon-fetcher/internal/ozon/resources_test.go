package ozon

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
)

func fixture(t *testing.T, name string) []byte {
	t.Helper()
	b, err := os.ReadFile(filepath.Join("testdata", filepath.Base(name)))
	require.NoError(t, err)
	return b
}

func sha(b []byte) string {
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:])
}

func clientFor(t *testing.T, handler http.Handler) *Client {
	t.Helper()
	srv := httptest.NewServer(handler)
	t.Cleanup(srv.Close)
	return NewClient(srv.URL, srv.Client(), slog.New(slog.NewTextHandler(io.Discard, nil)), time.Now)
}

func decodeBody(t *testing.T, r *http.Request) map[string]any {
	t.Helper()
	var m map[string]any
	require.NoError(t, json.NewDecoder(r.Body).Decode(&m))
	return m
}

func byDayServer(t *testing.T, pages map[string][]byte) (http.Handler, *[]string) {
	var mu sync.Mutex
	var cursors []string
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body := decodeBody(t, r)
		assert.Equal(t, "2026-10-15", body["date"])
		cursor, _ := body["last_id"].(string)
		mu.Lock()
		cursors = append(cursors, cursor)
		mu.Unlock()
		page, ok := pages[cursor]
		if !ok {
			w.WriteHeader(http.StatusBadRequest)
			return
		}
		_, _ = w.Write(page)
	}), &cursors
}

func TestFetchAccrualByDayWalksAllPagesInOrder(t *testing.T) {
	p1, p2 := fixture(t, "by_day_page1.json"), fixture(t, "by_day_page2.json")
	handler, cursors := byDayServer(t, map[string][]byte{"": p1, "cursor-1": p2})
	client := clientFor(t, handler)

	var got []Page
	err := client.FetchAccrualByDay(context.Background(), testRef, testCreds, "2026-10-15", "", 1, func(p Page) error {
		got = append(got, p)
		return nil
	})

	require.NoError(t, err)
	require.Len(t, got, 2)
	assert.Equal(t, []string{"", "cursor-1"}, *cursors)
	assert.Equal(t, 1, got[0].No)
	assert.Equal(t, "cursor-1", got[0].Cursor)
	assert.Equal(t, sha(p1), sha(got[0].Body), "страница — исходные байты")
	assert.Equal(t, 2, got[1].No)
	assert.Empty(t, got[1].Cursor)
	assert.Equal(t, sha(p2), sha(got[1].Body))
}

func TestFetchAccrualByDayResumesFromCursor(t *testing.T) {
	handler, cursors := byDayServer(t, map[string][]byte{"cursor-1": fixture(t, "by_day_page2.json")})
	client := clientFor(t, handler)

	var got []Page
	err := client.FetchAccrualByDay(context.Background(), testRef, testCreds, "2026-10-15", "cursor-1", 2, func(p Page) error {
		got = append(got, p)
		return nil
	})

	require.NoError(t, err)
	assert.Equal(t, []string{"cursor-1"}, *cursors)
	require.Len(t, got, 1)
	assert.Equal(t, 2, got[0].No)
}

func TestFetchAccrualByDayStopsWhenHandlerFails(t *testing.T) {
	handler, cursors := byDayServer(t, map[string][]byte{"": fixture(t, "by_day_page1.json"), "cursor-1": fixture(t, "by_day_page2.json")})
	client := clientFor(t, handler)
	storeErr := errors.New("s3 down")

	err := client.FetchAccrualByDay(context.Background(), testRef, testCreds, "2026-10-15", "", 1, func(Page) error { return storeErr })

	require.ErrorIs(t, err, storeErr)
	assert.Len(t, *cursors, 1, "следующая страница не запрошена, пока текущая не сохранена")
}

func TestFetchAccrualByDayRejectsBrokenResponses(t *testing.T) {
	tests := []struct {
		name  string
		pages map[string][]byte
	}{
		{"no accruals", map[string][]byte{"": []byte(`{"last_id":""}`)}},
		{"accruals not array", map[string][]byte{"": []byte(`{"accruals":{},"last_id":""}`)}},
		{"not json", map[string][]byte{"": []byte(`<html>`)}},
		{"repeated cursor", map[string][]byte{"": []byte(`{"accruals":[],"last_id":"a"}`), "a": []byte(`{"accruals":[],"last_id":"a"}`)}},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			handler, _ := byDayServer(t, tt.pages)
			err := clientFor(t, handler).FetchAccrualByDay(context.Background(), testRef, testCreds, "2026-10-15", "", 1, func(Page) error { return nil })

			var apiErr *APIError
			require.ErrorAs(t, err, &apiErr)
			assert.Equal(t, ClassInvalidResponse, apiErr.Class)
		})
	}
}

func TestFetchAccrualByDayPageLimit(t *testing.T) {
	var n int
	client := clientFor(t, http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		n++
		_, _ = w.Write([]byte(`{"accruals":[],"last_id":"c` + strings.Repeat("x", n) + `"}`))
	}))

	err := client.FetchAccrualByDay(context.Background(), testRef, testCreds, "2026-10-15", "", 1, func(Page) error { return nil })

	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ClassInvalidResponse, apiErr.Class)
	assert.Equal(t, MaxAccrualPages, n)
}

func TestFetchAccrualTypes(t *testing.T) {
	types := fixture(t, "accrual_types.json")
	page, err := clientFor(t, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		assert.Equal(t, EndpointAccrualTypes, r.URL.Path)
		_, _ = w.Write(types)
	})).FetchAccrualTypes(context.Background(), testRef, testCreds)

	require.NoError(t, err)
	assert.Equal(t, sha(types), sha(page.Body))

	_, err = clientFor(t, http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte(`{"result":[]}`))
	})).FetchAccrualTypes(context.Background(), testRef, testCreds)
	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ClassInvalidResponse, apiErr.Class)
}

func TestFetchRealization(t *testing.T) {
	report := fixture(t, "realization.json")
	tests := []struct {
		name      string
		status    int
		body      []byte
		wantClass Class
	}{
		{"ready", 200, report, ""},
		{"empty rows", 200, []byte(`{"result":{"rows":[]}}`), ClassNotReady},
		{"no rows", 200, []byte(`{"result":{}}`), ClassNotReady},
		{"not formed 4xx", 400, []byte(`{"code":3,"message":"report not found"}`), ClassNotReady},
		{"rate limit", 429, nil, ClassRateLimit},
		{"server", 500, nil, ClassTransient},
		{"auth", 401, nil, ClassAuth},
		{"obsolete", 400, []byte(`{"code":9,"message":"obsolete method cannot be used"}`), ClassObsoleteEndpoint},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			page, err := clientFor(t, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				body := decodeBody(t, r)
				assert.InDelta(t, 9, body["month"], 0)
				assert.InDelta(t, 2026, body["year"], 0)
				w.WriteHeader(tt.status)
				_, _ = w.Write(tt.body)
			})).FetchRealization(context.Background(), testRef, testCreds, 2026, 9)

			if tt.wantClass == "" {
				require.NoError(t, err)
				assert.Equal(t, sha(report), sha(page.Body))
				return
			}
			var apiErr *APIError
			require.ErrorAs(t, err, &apiErr)
			assert.Equal(t, tt.wantClass, apiErr.Class)
		})
	}
}

type msScript struct {
	infoStatuses []string // статусы report/info по очереди
	fileLink     string   // "" — подставить ссылку на сам сервер
	fileStatus   int
	fileBody     []byte
}

func mutualSettlementServer(t *testing.T, s msScript) (*httptest.Server, *int) {
	t.Helper()
	polls := 0
	var srv *httptest.Server
	srv = httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case EndpointMutualSettlement:
			body := decodeBody(t, r)
			assert.Equal(t, "2026-09", body["date"])
			_, _ = w.Write([]byte(`{"result":{"code":"rep-42"}}`))
		case EndpointReportInfo:
			assert.Equal(t, "rep-42", decodeBody(t, r)["code"])
			status := s.infoStatuses[min(polls, len(s.infoStatuses)-1)]
			polls++
			link := s.fileLink
			if link == "" {
				link = srv.URL + "/file.xlsx"
			}
			_, _ = w.Write([]byte(`{"result":{"status":"` + status + `","file":"` + link + `"}}`))
		case "/file.xlsx":
			assert.Empty(t, r.Header.Get("Api-Key"), "pre-signed ссылка — без ключей кабинета")
			w.Header().Set("Content-Type", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
			w.WriteHeader(s.fileStatus)
			_, _ = w.Write(s.fileBody)
		default:
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	t.Cleanup(srv.Close)
	return srv, &polls
}

var fastPoll = ReportPoll{Initial: time.Millisecond, Max: 4 * time.Millisecond, Deadline: 10 * time.Millisecond}

func noSleep(context.Context, time.Duration) error { return nil }

func TestFetchMutualSettlement(t *testing.T) {
	xlsx := []byte("PK\x03\x04fake-xlsx-bytes")
	tests := []struct {
		name      string
		script    msScript
		wantClass Class
		wantPolls int
	}{
		{"ready after polling", msScript{infoStatuses: []string{"waiting", "processing", "success"}, fileStatus: 200, fileBody: xlsx}, "", 3},
		{"generation failed", msScript{infoStatuses: []string{"failed"}}, ClassTransient, 1},
		{"deadline", msScript{infoStatuses: []string{"waiting"}}, ClassNotReady, 4},
		{"http link", msScript{infoStatuses: []string{"success"}, fileLink: "http://files.example/x.xlsx"}, ClassInvalidResponse, 1},
		{"expired link", msScript{infoStatuses: []string{"success"}, fileStatus: 403}, ClassNotReady, 1},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			srv, polls := mutualSettlementServer(t, tt.script)
			client := NewClient(srv.URL, srv.Client(), slog.New(slog.NewTextHandler(io.Discard, nil)), time.Now)

			page, err := client.FetchMutualSettlement(context.Background(), testRef, testCreds, 2026, 9, fastPoll, noSleep)

			assert.Equal(t, tt.wantPolls, *polls)
			if tt.wantClass == "" {
				require.NoError(t, err)
				assert.Equal(t, sha(xlsx), sha(page.Body))
				assert.Contains(t, page.ContentType, "spreadsheetml")
				return
			}
			var apiErr *APIError
			require.ErrorAs(t, err, &apiErr)
			assert.Equal(t, tt.wantClass, apiErr.Class)
		})
	}
}

func TestFetchMutualSettlementDeadlineCountsSlowStatusRequests(t *testing.T) {
	srv, polls := mutualSettlementServer(t, msScript{infoStatuses: []string{"waiting"}})
	slow := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == EndpointReportInfo {
			time.Sleep(40 * time.Millisecond)
		}
		srv.Config.Handler.ServeHTTP(w, r)
	})
	slowSrv := httptest.NewTLSServer(slow)
	t.Cleanup(slowSrv.Close)
	client := NewClient(slowSrv.URL, slowSrv.Client(), slog.New(slog.NewTextHandler(io.Discard, nil)), time.Now)
	poll := ReportPoll{Initial: time.Millisecond, Max: time.Millisecond, Deadline: 100 * time.Millisecond}

	started := time.Now()
	_, err := client.FetchMutualSettlement(context.Background(), testRef, testCreds, 2026, 9, poll, noSleep)

	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ClassNotReady, apiErr.Class)
	assert.Less(t, time.Since(started), time.Second, "дедлайн — по реальному времени, а не по сумме пауз")
	assert.LessOrEqual(t, *polls, 3)
}

func TestFetchMutualSettlementParentCancelIsNotNotReady(t *testing.T) {
	srv, _ := mutualSettlementServer(t, msScript{infoStatuses: []string{"waiting"}})
	client := NewClient(srv.URL, srv.Client(), slog.New(slog.NewTextHandler(io.Discard, nil)), time.Now)
	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	_, err := client.FetchMutualSettlement(ctx, testRef, testCreds, 2026, 9, fastPoll, SleepContext)

	assert.ErrorIs(t, err, context.Canceled)
}

func TestDownloadErrorDoesNotLeakSignedURL(t *testing.T) {
	const signature = "X-Amz-Signature=very-secret-signature"
	srv, _ := mutualSettlementServer(t, msScript{infoStatuses: []string{"success"}, fileLink: "https://127.0.0.1:1/file.xlsx?" + signature})
	client := NewClient(srv.URL, srv.Client(), slog.New(slog.NewTextHandler(io.Discard, nil)), time.Now)

	_, err := client.FetchMutualSettlement(context.Background(), testRef, testCreds, 2026, 9, fastPoll, noSleep)

	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ClassTransient, apiErr.Class)
	assert.NotContains(t, err.Error(), "very-secret-signature")
	assert.NotContains(t, fmt.Sprintf("%+v", err), "very-secret-signature")
	asJSON, _ := json.Marshal(apiErr)
	assert.NotContains(t, string(asJSON), "very-secret-signature")
}

func TestFetchMutualSettlementWithoutCode(t *testing.T) {
	client := clientFor(t, http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte(`{"result":{}}`))
	}))

	_, err := client.FetchMutualSettlement(context.Background(), testRef, testCreds, 2026, 9, fastPoll, noSleep)

	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ClassInvalidResponse, apiErr.Class)
}

type recordingLimiter struct {
	mu     sync.Mutex
	events []string
}

func (l *recordingLimiter) Wait(_ context.Context, ref account.Ref, endpoint string) error {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.events = append(l.events, "wait "+ref.AccountID+" "+endpoint)
	return nil
}

func (l *recordingLimiter) Observe(ref account.Ref, endpoint string, apiErr *APIError) {
	l.mu.Lock()
	defer l.mu.Unlock()
	outcome := "ok"
	if apiErr != nil {
		outcome = string(apiErr.Class)
	}
	l.events = append(l.events, "observe "+ref.AccountID+" "+endpoint+" "+outcome)
}

func TestLimiterWrapsEveryRequest(t *testing.T) {
	limiter := &recordingLimiter{}
	calls := 0
	client := clientFor(t, http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		calls++
		if calls == 1 {
			w.WriteHeader(http.StatusTooManyRequests)
			return
		}
		_, _ = w.Write(fixture(t, "accrual_types.json"))
	})).WithLimiter(limiter)

	_, err := client.FetchAccrualTypes(context.Background(), testRef, testCreds)
	require.Error(t, err)
	_, err = client.FetchAccrualTypes(context.Background(), testRef, testCreds)
	require.NoError(t, err)

	assert.Equal(t, []string{
		"wait " + testRef.AccountID + " " + EndpointAccrualTypes,
		"observe " + testRef.AccountID + " " + EndpointAccrualTypes + " rate_limit",
		"wait " + testRef.AccountID + " " + EndpointAccrualTypes,
		"observe " + testRef.AccountID + " " + EndpointAccrualTypes + " ok",
	}, limiter.events)
}

func TestSleepContextHonoursCancel(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	assert.ErrorIs(t, SleepContext(ctx, time.Hour), context.Canceled)
	assert.NoError(t, SleepContext(context.Background(), time.Millisecond))
}

func TestDownloadedZipIsLabelledXLSX(t *testing.T) {
	xlsx := []byte("PK\x03\x04rest-of-xlsx")
	srv, _ := mutualSettlementServer(t, msScript{infoStatuses: []string{"success"}, fileStatus: 200, fileBody: xlsx})
	srv.Config.Handler = func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			if r.URL.Path == "/file.xlsx" {
				w.Header().Set("Content-Type", "application/octet-stream")
				_, _ = w.Write(xlsx)
				return
			}
			next.ServeHTTP(w, r)
		})
	}(srv.Config.Handler)
	client := NewClient(srv.URL, srv.Client(), slog.New(slog.NewTextHandler(io.Discard, nil)), time.Now)

	page, err := client.FetchMutualSettlement(context.Background(), testRef, testCreds, 2026, 9, fastPoll, noSleep)

	require.NoError(t, err)
	assert.Contains(t, page.ContentType, "spreadsheetml")
}
