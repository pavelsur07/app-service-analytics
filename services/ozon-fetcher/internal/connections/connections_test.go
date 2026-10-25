package connections

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"os"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account/accounttest"
)

const token = "test-internal-token-0123456789abcdef" //nolint:gosec // G101: фиктивный токен теста

// examplePath — эталонная страница контракта; её же проверяет contract_test.go.
const examplePath = "../../../_contracts/marketplace-connections/v1/example.json"

// Подключения эталонной страницы.
var (
	refA = account.Ref{CompanyID: "0193a5e0-0000-7000-8000-00000000c0a1", AccountID: "0193a5e0-0000-7000-8000-0000000000a1"}
	refB = account.Ref{CompanyID: "0193a5e0-0000-7000-8000-00000000c0b1", AccountID: "0193a5e0-0000-7000-8000-0000000000b1"}
)

func example(t *testing.T) string {
	t.Helper()
	b, err := os.ReadFile(examplePath)
	require.NoError(t, err)
	return string(b)
}

// pagedServer отдаёт pages по курсору: первая — без cursor, i-я — cursor "p<i>".
func pagedServer(t *testing.T, pages ...string) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		i := 0
		if c := r.URL.Query().Get("cursor"); c != "" {
			n, err := strconv.Atoi(strings.TrimPrefix(c, "p"))
			if err != nil || n >= len(pages) {
				w.WriteHeader(http.StatusUnprocessableEntity)
				return
			}
			i = n
		}
		_, _ = io.WriteString(w, pages[i])
	}))
	t.Cleanup(srv.Close)
	return srv
}

func fetch(t *testing.T, pages ...string) ([]Cabinet, error) {
	t.Helper()
	srv := pagedServer(t, pages...)
	return NewClient(srv.URL, token, srv.Client()).Fetch(context.Background())
}

// pageOf — страница с connections (JSON-массив без скобок) и курсором.
func pageOf(connections string, next string) string {
	cursor := "null"
	if next != "" {
		cursor = strconv.Quote(next)
	}
	return `{"schema_version":"1","generated_at":"2026-10-24T12:00:00+03:00","connections":[` + connections + `],"next_cursor":` + cursor + `}`
}

func conn(ref account.Ref, extra string) string {
	return fmt.Sprintf(`{"marketplace_account_id":%q,"company_id":%q,"provider":"ozon","updated_at":"2026-10-11T09:00:00+03:00",`+
		`"credentials":{"seller":{"client_id":"1","api_key":"k"},"performance":null},"resource_types":[]%s}`, ref.AccountID, ref.CompanyID, extra)
}

func TestFetchParsesContractExample(t *testing.T) {
	cabinets, err := fetch(t, example(t))

	require.NoError(t, err)
	require.Len(t, cabinets, 2)
	a := cabinets[0]
	assert.Equal(t, refA, a.Account, "подключения упорядочены по marketplace_account_id")
	require.NotNil(t, a.Seller)
	assert.Equal(t, "seller-a", a.Seller.ClientID)
	assert.Equal(t, "example-seller-key-a", a.Seller.APIKey)
	assert.False(t, a.HasPerformance)
	assert.True(t, a.Supports("ozon.finance.accrual-by-day"))
	assert.False(t, a.Supports("ozon.finance.realization"))
	assert.True(t, a.UpdatedAt.Equal(time.Date(2026, 10, 11, 6, 0, 0, 0, time.UTC)))

	b := cabinets[1]
	assert.Equal(t, refB, b.Account)
	assert.Nil(t, b.Seller)
	assert.True(t, b.HasPerformance)
	require.NotNil(t, b.Performance)
	assert.Equal(t, "perf-b", b.Performance.ClientID)
	assert.Equal(t, "example-perf-secret-b", b.Performance.ClientSecret, "секрет нужен для токена рекламы")
	assert.NotContains(t, fmt.Sprintf("%v %+v %#v", b, b, b), "example-perf-secret-b", "при печати подключения секрет скрыт")
}

func TestFetchWalksPagesWithTokenProviderLimitAndCursor(t *testing.T) {
	var cursors []string
	pages := []string{pageOf(conn(refB, ""), "p1"), pageOf(conn(refA, ""), "")}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		assert.Equal(t, "/api/internal/marketplace-connections", r.URL.Path)
		assert.Equal(t, "ozon", r.URL.Query().Get("provider"))
		assert.Equal(t, "200", r.URL.Query().Get("limit"))
		assert.Equal(t, "Bearer "+token, r.Header.Get("Authorization"))
		c := r.URL.Query().Get("cursor")
		cursors = append(cursors, c)
		if c == "" {
			_, _ = io.WriteString(w, pages[0])
			return
		}
		_, _ = io.WriteString(w, pages[1])
	}))
	defer srv.Close()

	cabinets, err := NewClient(srv.URL, token, srv.Client()).Fetch(context.Background())

	require.NoError(t, err)
	assert.Equal(t, []string{"", "p1"}, cursors)
	require.Len(t, cabinets, 2)
	assert.Equal(t, refA, cabinets[0].Account, "список собран со всех страниц и упорядочен")
	assert.Equal(t, refB, cabinets[1].Account)
}

func TestFetchFailsWholeWalkOnBrokenPage(t *testing.T) {
	for name, second := range map[string]string{
		"not json":       `<html>`,
		"no next_cursor": `{"schema_version":"1","generated_at":"2026-10-24T12:00:00+03:00","connections":[]}`,
		"cursor stuck":   pageOf(conn(refA, ""), "p1"),
		"empty cursor":   `{"schema_version":"1","generated_at":"2026-10-24T12:00:00+03:00","connections":[],"next_cursor":""}`,
	} {
		t.Run(name, func(t *testing.T) {
			cabinets, err := fetch(t, pageOf(conn(refB, ""), "p1"), second)
			require.ErrorIs(t, err, ErrInvalidSnapshot)
			assert.Nil(t, cabinets, "неполный список не возвращается")
		})
	}

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("cursor") == "" {
			_, _ = io.WriteString(w, pageOf(conn(refB, ""), "p1"))
			return
		}
		w.WriteHeader(http.StatusServiceUnavailable)
	}))
	defer srv.Close()
	cabinets, err := NewClient(srv.URL, token, srv.Client()).Fetch(context.Background())
	require.Error(t, err)
	assert.Nil(t, cabinets, "сбой второй страницы — отказ всего обхода")
}

func TestFetchRejectsPageOverLimit(t *testing.T) {
	items := make([]string, 0, PageLimit+1)
	for i := range PageLimit + 1 {
		items = append(items, conn(accounttest.Ref(strconv.Itoa(i)), ""))
	}

	_, err := fetch(t, pageOf(strings.Join(items, ","), ""))

	assert.ErrorIs(t, err, ErrInvalidSnapshot)
}

func TestFetchRejectsInvalidPages(t *testing.T) {
	for name, body := range map[string]string{
		"not json":         `<html>`,
		"unknown version":  `{"schema_version":"2","connections":[],"next_cursor":null}`,
		"no version":       `{"connections":[],"next_cursor":null}`,
		"no connections":   `{"schema_version":"1","generated_at":"2026-10-17T12:00:00+03:00","next_cursor":null}`,
		"no generated_at":  `{"schema_version":"1","connections":[],"next_cursor":null}`,
		"bad generated_at": `{"schema_version":"1","generated_at":"today","connections":[],"next_cursor":null}`,
	} {
		t.Run(name, func(t *testing.T) {
			_, err := fetch(t, body)
			assert.ErrorIs(t, err, ErrInvalidSnapshot)
		})
	}
}

// Битое подключение — нарушение контракта всего обхода, не пропуск: пропуск
// сервис не отличил бы от отзыва подключения (services/AGENTS.md §3).
func TestFetchRejectsWholeWalkOnBrokenConnection(t *testing.T) {
	ok := accounttest.Ref("ok")
	head := `"marketplace_account_id":"` + ok.AccountID + `","company_id":"` + ok.CompanyID + `"`
	tail := `"provider":"ozon","updated_at":"2026-10-11T09:00:00+03:00"`
	creds := `"credentials":{"seller":null,"performance":null}`
	for name, broken := range map[string]string{
		"account not uuid":     `{"marketplace_account_id":"not-a-uuid","company_id":"` + ok.CompanyID + `",` + tail + `,` + creds + `,"resource_types":[]}`,
		"no company":           `{"marketplace_account_id":"` + ok.AccountID + `","company_id":"",` + tail + `,` + creds + `,"resource_types":[]}`,
		"other provider":       `{` + head + `,"provider":"wildberries","updated_at":"2026-10-11T09:00:00+03:00",` + creds + `,"resource_types":[]}`,
		"bad updated_at":       `{` + head + `,"provider":"ozon","updated_at":"yesterday",` + creds + `,"resource_types":[]}`,
		"comma updated_at":     `{` + head + `,"provider":"ozon","updated_at":"2026-10-11T09:00:00,5+03:00",` + creds + `,"resource_types":[]}`,
		"offset hour 24":       `{` + head + `,"provider":"ozon","updated_at":"2026-10-11T09:00:00+24:00",` + creds + `,"resource_types":[]}`,
		"single digit hour":    `{` + head + `,"provider":"ozon","updated_at":"2026-10-11T9:00:00Z",` + creds + `,"resource_types":[]}`,
		"empty api_key":        `{` + head + `,` + tail + `,"credentials":{"seller":{"client_id":"1","api_key":""},"performance":null},"resource_types":[]}`,
		"no credentials":       `{` + head + `,` + tail + `,"resource_types":[]}`,
		"no seller field":      `{` + head + `,` + tail + `,"credentials":{"performance":null},"resource_types":[]}`,
		"no performance field": `{` + head + `,` + tail + `,"credentials":{"seller":null},"resource_types":[]}`,
		"no resource_types":    `{` + head + `,` + tail + `,` + creds + `}`,
		"empty resource_type":  `{` + head + `,` + tail + `,` + creds + `,"resource_types":[""]}`,
		"unknown field":        `{` + head + `,` + tail + `,` + creds + `,"resource_types":[],"extra":1}`,
		"unknown seller field": `{` + head + `,` + tail + `,"credentials":{"seller":{"client_id":"1","api_key":"k","x":1},"performance":null},"resource_types":[]}`,
		"seller without key":   `{` + head + `,` + tail + `,"credentials":{"seller":{"client_id":"1"},"performance":null},"resource_types":[]}`,
		"credentials null":     `{` + head + `,` + tail + `,"credentials":null,"resource_types":[]}`,
		"company key case":     `{` + head + `,"Company_ID":"` + accounttest.Ref("other").CompanyID + `",` + tail + `,` + creds + `,"resource_types":[]}`,
		"seller key case":      `{` + head + `,` + tail + `,"credentials":{"Seller":{"client_id":"1","api_key":"k"},"performance":null},"resource_types":[]}`,
	} {
		t.Run(name, func(t *testing.T) {
			cabinets, err := fetch(t, pageOf(conn(accounttest.Ref("fine"), "")+","+broken, ""))
			require.ErrorIs(t, err, ErrInvalidSnapshot)
			assert.Nil(t, cabinets)
			assert.NotContains(t, err.Error(), `"k"`, "значения ключей в ошибку не попадают")
		})
	}
}

// Один marketplace_account_id под двумя компаниями: какая запись верна,
// неизвестно — отвергается весь обход, а не только второй экземпляр.
func TestFetchRejectsAccountUnderTwoCompanies(t *testing.T) {
	ok := accounttest.Ref("ok")
	twin := account.Ref{CompanyID: accounttest.Ref("other").CompanyID, AccountID: ok.AccountID}
	for name, pages := range map[string][]string{
		"same page":  {pageOf(conn(ok, "")+","+conn(twin, ""), "")},
		"next pages": {pageOf(conn(ok, ""), "p1"), pageOf(conn(twin, ""), "")},
	} {
		t.Run(name, func(t *testing.T) {
			cabinets, err := fetch(t, pages...)
			require.ErrorIs(t, err, ErrInvalidSnapshot)
			assert.Nil(t, cabinets)
		})
	}
}

// Формы date-time, допустимые RFC 3339, но не time.Parse(time.RFC3339).
func TestParseDateTimeFollowsRFC3339(t *testing.T) {
	for _, ok := range []string{"2026-10-24T12:00:00Z", "2026-10-24t12:00:00z", "2026-10-24T12:00:00.5+03:00", "2016-12-31T23:59:60Z"} {
		_, err := parseDateTime(ok)
		assert.NoError(t, err, ok)
	}
	for _, bad := range []string{"2026-10-24T12:00:00,5Z", "2026-10-24T1:00:00Z", "2026-10-24T12:00:00+24:00", "2026-10-24T12:00:00+03:60", "2026-10-24 12:00:00Z", "2026-13-01T00:00:00Z"} {
		_, err := parseDateTime(bad)
		assert.Error(t, err, bad)
	}
}

func TestFetchRejectsPageOutsideSchemaKeys(t *testing.T) {
	page := `{"schema_version":"1","generated_at":"2026-10-24T12:00:00+03:00","connections":[],"next_cursor":null`
	for name, body := range map[string]string{
		"unknown field":  page + `,"extra":true}`,
		"key case":       `{"SCHEMA_VERSION":"1","generated_at":"2026-10-24T12:00:00+03:00","connections":[],"next_cursor":null}`,
		"trailing brace": page + `}}`,
		"trailing value": page + `} {}`,
		"comma fraction": `{"schema_version":"1","generated_at":"2026-10-24T12:00:00,5+03:00","connections":[],"next_cursor":null}`,
	} {
		t.Run(name, func(t *testing.T) {
			_, err := fetch(t, body)
			assert.ErrorIs(t, err, ErrInvalidSnapshot)
		})
	}
}

func TestClientFetchFailsOnNon200(t *testing.T) {
	for _, status := range []int{401, 403, 422, 500, 503} {
		srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			w.WriteHeader(status)
		}))
		_, err := NewClient(srv.URL, token, srv.Client()).Fetch(context.Background())
		srv.Close()
		require.Error(t, err)
		assert.NotContains(t, err.Error(), token)
	}
}

type scriptedFetcher struct {
	mu      sync.Mutex
	results []fetchResult
	calls   int
}

type fetchResult struct {
	cabinets []Cabinet
	err      error
}

func (f *scriptedFetcher) Fetch(context.Context) ([]Cabinet, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	r := f.results[min(f.calls, len(f.results)-1)]
	f.calls++
	return r.cabinets, r.err
}

type clock struct {
	mu  sync.Mutex
	now time.Time
}

func (c *clock) Now() time.Time {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.now
}

func (c *clock) Advance(d time.Duration) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.now = c.now.Add(d)
}

// newCache — кэш с allowlist из всех подключений тестов: allowlist закрыт
// по умолчанию, и без него снимок был бы пуст.
func newCache(f Fetcher) (*Cache, *clock, *bytes.Buffer) {
	logs := &bytes.Buffer{}
	clk := &clock{now: time.Date(2026, 10, 17, 12, 0, 0, 0, time.UTC)}
	allow := []string{refA.AccountID, refB.AccountID, accounttest.Ref("ref-a").AccountID, accounttest.Ref("ref-b").AccountID}
	return NewCache(f, slog.New(slog.NewJSONHandler(logs, nil)), clk.Now).WithAllowlist(allow), clk, logs
}

func exampleCabinets(t *testing.T) []Cabinet {
	t.Helper()
	cabinets, err := fetch(t, example(t))
	require.NoError(t, err)
	return cabinets
}

func TestCacheKeepsLastSnapshotOnFailure(t *testing.T) {
	first := []Cabinet{{Account: accounttest.Ref("ref-a")}, {Account: accounttest.Ref("ref-b")}}
	f := &scriptedFetcher{results: []fetchResult{{cabinets: first}, {err: errors.New("HTTP 503")}}}
	cache, _, logs := newCache(f)

	assert.False(t, cache.Ready())
	assert.Empty(t, cache.List())

	require.NoError(t, cache.Refresh(context.Background()))
	require.Error(t, cache.Refresh(context.Background()))

	assert.True(t, cache.Ready())
	assert.Len(t, cache.List(), 2)
	_, ok := cache.Get(accounttest.Ref("ref-b"))
	assert.True(t, ok)
	assert.Contains(t, logs.String(), "keeping last snapshot")
}

func TestCacheReplacesSnapshotAndDropsVanishedCabinets(t *testing.T) {
	f := &scriptedFetcher{results: []fetchResult{{cabinets: []Cabinet{{Account: accounttest.Ref("ref-a")}, {Account: accounttest.Ref("ref-b")}}}, {cabinets: []Cabinet{{Account: accounttest.Ref("ref-a")}}}}}
	cache, _, _ := newCache(f)

	require.NoError(t, cache.Refresh(context.Background()))
	require.NoError(t, cache.Refresh(context.Background()))

	assert.Len(t, cache.List(), 1)
	_, ok := cache.Get(accounttest.Ref("ref-b"))
	assert.False(t, ok)
}

func TestCacheGetNeedsBothCompanyAndAccount(t *testing.T) {
	cache, _, _ := newCache(&scriptedFetcher{results: []fetchResult{{cabinets: exampleCabinets(t)}}})
	require.NoError(t, cache.Refresh(context.Background()))

	_, ok := cache.Get(account.Ref{CompanyID: refB.CompanyID, AccountID: refA.AccountID})
	assert.False(t, ok, "подключение одной компании не выдаётся под другой")
	_, ok = cache.Get(refA)
	assert.True(t, ok)
}

func TestForceRefreshIsThrottled(t *testing.T) {
	f := &scriptedFetcher{results: []fetchResult{{cabinets: []Cabinet{{Account: accounttest.Ref("ref-a")}}}}}
	cache, clk, _ := newCache(f)
	require.NoError(t, cache.Refresh(context.Background()))

	done, err := cache.ForceRefresh(context.Background())
	require.NoError(t, err)
	assert.False(t, done, "только что обновлялись")

	clk.Advance(ForceRefreshMinInterval)
	done, err = cache.ForceRefresh(context.Background())
	require.NoError(t, err)
	assert.True(t, done)
	assert.Equal(t, 2, f.calls)
}

// Неудачное обновление не выдаёт старый снимок за свежий: RefreshedAt —
// только у успешного.
func TestRefreshedAtMovesOnlyOnSuccess(t *testing.T) {
	f := &scriptedFetcher{results: []fetchResult{{cabinets: []Cabinet{{Account: accounttest.Ref("ref-a")}}}, {err: errors.New("php down")}}}
	cache, clk, _ := newCache(f)
	assert.True(t, cache.RefreshedAt().IsZero())
	require.NoError(t, cache.Refresh(context.Background()))
	first := clk.Now()
	assert.Equal(t, first, cache.RefreshedAt())

	clk.Advance(ForceRefreshMinInterval)
	_, err := cache.ForceRefresh(context.Background())
	require.Error(t, err)
	assert.Equal(t, first, cache.RefreshedAt())
	done, err := cache.ForceRefresh(context.Background())
	require.NoError(t, err)
	assert.False(t, done, "подавлено после неудачной попытки")
	assert.Equal(t, first, cache.RefreshedAt(), "подавленный запрос не делает снимок свежим")
}

func TestListAndGetReturnDeepCopies(t *testing.T) {
	cache, _, _ := newCache(&scriptedFetcher{results: []fetchResult{{cabinets: exampleCabinets(t)}}})
	require.NoError(t, cache.Refresh(context.Background()))

	list := cache.List()
	list[0].Account = accounttest.Ref("mutated")
	list[0].Seller.APIKey = "mutated"
	list[0].ResourceTypes[0] = "mutated"
	got, ok := cache.Get(refA)
	require.True(t, ok)
	got.Seller.APIKey = "mutated-too"
	got.ResourceTypes[0] = "mutated-too"

	fresh, _ := cache.Get(refA)
	assert.Equal(t, refA, cache.List()[0].Account)
	assert.Equal(t, "example-seller-key-a", fresh.Seller.APIKey)
	assert.Equal(t, "ozon.finance.accrual-by-day", fresh.ResourceTypes[0])
}

func TestInvalidSnapshotPreservesCache(t *testing.T) {
	var srvBody atomic.Value
	srvBody.Store(example(t))
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = io.WriteString(w, srvBody.Load().(string))
	}))
	defer srv.Close()
	cache, _, _ := newCache(NewClient(srv.URL, token, srv.Client()))
	require.NoError(t, cache.Refresh(context.Background()))

	srvBody.Store(`{"schema_version":"1","connections":[],"next_cursor":null}`)
	require.ErrorIs(t, cache.Refresh(context.Background()), ErrInvalidSnapshot)

	assert.Len(t, cache.List(), 2, "битая выдача не обнуляет кэш")
}

func TestRunRefreshesUntilCanceled(t *testing.T) {
	var calls atomic.Int32
	f := fetcherFunc(func(context.Context) ([]Cabinet, error) {
		calls.Add(1)
		return []Cabinet{{Account: accounttest.Ref("ref-a")}}, nil
	})
	cache := NewCache(f, slog.New(slog.NewTextHandler(io.Discard, nil)), time.Now)
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		cache.Run(ctx, 5*time.Millisecond)
		close(done)
	}()

	require.Eventually(t, func() bool { return calls.Load() >= 3 }, time.Second, time.Millisecond)
	cancel()
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("Run did not stop")
	}
	assert.True(t, cache.Ready())
}

func TestCabinetCredentialsAreNotLogged(t *testing.T) {
	cache, _, logs := newCache(&scriptedFetcher{results: []fetchResult{{cabinets: exampleCabinets(t)}}})

	require.NoError(t, cache.Refresh(context.Background()))
	slog.New(slog.NewJSONHandler(logs, nil)).Info("debug", slog.Any("cabinet", cache.List()[0]))

	assert.False(t, strings.Contains(logs.String(), "example-seller-key-a"), logs.String())
}

type fetcherFunc func(context.Context) ([]Cabinet, error)

func (f fetcherFunc) Fetch(ctx context.Context) ([]Cabinet, error) { return f(ctx) }

// ADR-043 п. 5: allowlist закрыт по умолчанию и поимённый — только
// marketplace_account_id; пустой список — ни одного подключения.
func TestAllowlistKeepsOnlyListedCabinets(t *testing.T) {
	cabinets := exampleCabinets(t)
	logs := &bytes.Buffer{}
	newFor := func(allow []string) *Cache {
		return NewCache(&scriptedFetcher{results: []fetchResult{{cabinets: cabinets}}}, slog.New(slog.NewJSONHandler(logs, nil)), time.Now).WithAllowlist(allow)
	}

	cache := newFor([]string{" " + refA.AccountID + " ", ""})
	require.NoError(t, cache.Refresh(context.Background()))
	list := cache.List()
	require.Len(t, list, 1)
	assert.Equal(t, refA, list[0].Account)
	_, ok := cache.Get(refB)
	assert.False(t, ok)
	assert.Contains(t, logs.String(), `"issued":2`, "в журнале видно, сколько выдал PHP до фильтра")

	for name, allow := range map[string][]string{
		"empty":      nil,
		"blank":      {"", " "},
		"company_id": {refA.CompanyID},
	} {
		t.Run(name, func(t *testing.T) {
			cache := newFor(allow)
			require.NoError(t, cache.Refresh(context.Background()))
			assert.Empty(t, cache.List(), "ни одного подключения")
			assert.True(t, cache.Ready(), "снимок при этом получен")
		})
	}
	cache = NewCache(&scriptedFetcher{results: []fetchResult{{cabinets: cabinets}}}, slog.New(slog.NewJSONHandler(logs, nil)), time.Now)
	require.NoError(t, cache.Refresh(context.Background()))
	assert.Empty(t, cache.List(), "без WithAllowlist — тоже ни одного")
}

// ADR-043 п. 4: снимок без подтверждения выдачей живёт не дольше maxStale;
// неудачное обновление срок не продлевает, удачное — восстанавливает.
func TestSnapshotIsFreshOnlyWithinMaxStale(t *testing.T) {
	f := &scriptedFetcher{results: []fetchResult{{cabinets: []Cabinet{{Account: accounttest.Ref("ref-a")}}}, {err: errors.New("php down")}, {cabinets: []Cabinet{{Account: accounttest.Ref("ref-a")}}}}}
	cache, clk, _ := newCache(f)
	cache.WithMaxStale(30 * time.Minute)
	assert.False(t, cache.Fresh(), "снимка ещё нет")

	require.NoError(t, cache.Refresh(context.Background()))
	clk.Advance(30 * time.Minute)
	assert.True(t, cache.Fresh(), "ровно на сроке — ещё годен")
	require.Error(t, cache.Refresh(context.Background()))
	clk.Advance(time.Second)
	assert.False(t, cache.Fresh(), "неудачное обновление срок не продлевает")
	_, ok := cache.Get(accounttest.Ref("ref-a"))
	assert.True(t, ok, "ключи последнего снимка в памяти, но задач по ним нет — решает Fresh")

	require.NoError(t, cache.Refresh(context.Background()))
	assert.True(t, cache.Fresh())
}

// Переход в «устарел» — одна ERROR-запись и уведомление подписчиков;
// повторная проверка молчит; подтверждение — info и снова уведомление.
func TestStaleTransitionIsReportedOnceAndNotified(t *testing.T) {
	f := &scriptedFetcher{results: []fetchResult{{cabinets: []Cabinet{{Account: accounttest.Ref("ref-a")}}}, {cabinets: []Cabinet{{Account: accounttest.Ref("ref-a")}}}}}
	cache, clk, logs := newCache(f)
	cache.WithMaxStale(time.Minute)
	var notified int
	cache.OnChange(func() { notified++ })

	cache.checkStale()
	assert.Zero(t, notified, "без снимка устаревать нечему")
	require.NoError(t, cache.Refresh(context.Background()))
	assert.Equal(t, 1, notified, "каждое успешное обновление — подписчикам")

	clk.Advance(2 * time.Minute)
	cache.checkStale()
	cache.checkStale()
	assert.Equal(t, 2, notified, "переход сообщается один раз")
	assert.Equal(t, 1, strings.Count(logs.String(), `"level":"ERROR"`))
	assert.Contains(t, logs.String(), "connections_stale")

	require.NoError(t, cache.Refresh(context.Background()))
	assert.Equal(t, 3, notified)
	assert.Contains(t, logs.String(), "confirmed again")
	clk.Advance(2 * time.Minute)
	cache.checkStale()
	assert.Equal(t, 2, strings.Count(logs.String(), `"level":"ERROR"`), "новое устаревание — новая запись")
}

// Run замечает истечение срока таймером, не дожидаясь следующего поллинга.
func TestRunReportsStaleWithoutWaitingForThePoll(t *testing.T) {
	var calls atomic.Int32
	f := fetcherFunc(func(context.Context) ([]Cabinet, error) {
		if calls.Add(1) == 1 {
			return []Cabinet{{Account: accounttest.Ref("ref-a")}}, nil
		}
		return nil, errors.New("php down")
	})
	cache := NewCache(f, slog.New(slog.NewTextHandler(io.Discard, nil)), time.Now).
		WithAllowlist([]string{accounttest.Ref("ref-a").AccountID}).WithMaxStale(30 * time.Millisecond)
	var notified atomic.Int32
	cache.OnChange(func() { notified.Add(1) })
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		cache.Run(ctx, time.Hour)
		close(done)
	}()

	require.Eventually(t, func() bool { return notified.Load() == 2 }, 2*time.Second, time.Millisecond, "обновление и устаревание")
	assert.False(t, cache.Fresh())
	assert.Equal(t, int32(1), calls.Load(), "поллинг ещё не наступал")
	cancel()
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("Run did not stop")
	}
}
