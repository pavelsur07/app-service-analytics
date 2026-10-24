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

func fetch(t *testing.T, pages ...string) ([]Cabinet, []string, error) {
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
	cabinets, rejected, err := fetch(t, example(t))

	require.NoError(t, err)
	assert.Empty(t, rejected)
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

	cabinets, _, err := NewClient(srv.URL, token, srv.Client()).Fetch(context.Background())

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
			cabinets, _, err := fetch(t, pageOf(conn(refB, ""), "p1"), second)
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
	cabinets, _, err := NewClient(srv.URL, token, srv.Client()).Fetch(context.Background())
	require.Error(t, err)
	assert.Nil(t, cabinets, "сбой второй страницы — отказ всего обхода")
}

func TestFetchRejectsPageOverLimit(t *testing.T) {
	items := make([]string, 0, PageLimit+1)
	for i := range PageLimit + 1 {
		items = append(items, conn(accounttest.Ref(strconv.Itoa(i)), ""))
	}

	_, _, err := fetch(t, pageOf(strings.Join(items, ","), ""))

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
			_, _, err := fetch(t, body)
			assert.ErrorIs(t, err, ErrInvalidSnapshot)
		})
	}
}

func TestFetchSkipsBrokenConnections(t *testing.T) {
	ok := accounttest.Ref("ok")
	body := pageOf(strings.Join([]string{
		conn(ok, ""),
		`{"marketplace_account_id":"not-a-uuid","company_id":"` + ok.CompanyID + `","provider":"ozon","updated_at":"2026-10-11T09:00:00+03:00","credentials":{"seller":null,"performance":null},"resource_types":[]}`,
		`{"marketplace_account_id":"` + accounttest.Ref("no-company").AccountID + `","company_id":"","provider":"ozon","updated_at":"2026-10-11T09:00:00+03:00","credentials":{"seller":null,"performance":null},"resource_types":[]}`,
		`{"marketplace_account_id":"` + accounttest.Ref("wb").AccountID + `","company_id":"` + ok.CompanyID + `","provider":"wildberries","updated_at":"2026-10-11T09:00:00+03:00","credentials":{"seller":null,"performance":null},"resource_types":[]}`,
		`{"marketplace_account_id":"` + accounttest.Ref("bad-date").AccountID + `","company_id":"` + ok.CompanyID + `","provider":"ozon","updated_at":"yesterday","credentials":{"seller":null,"performance":null},"resource_types":[]}`,
		`{"marketplace_account_id":"` + accounttest.Ref("empty-key").AccountID + `","company_id":"` + ok.CompanyID + `","provider":"ozon","updated_at":"2026-10-11T09:00:00+03:00","credentials":{"seller":{"client_id":"1","api_key":""},"performance":null},"resource_types":[]}`,
		`{"marketplace_account_id":"` + accounttest.Ref("no-credentials").AccountID + `","company_id":"` + ok.CompanyID + `","provider":"ozon","updated_at":"2026-10-11T09:00:00+03:00","resource_types":[]}`,
		// Тот же marketplace_account_id под другой компанией — дубль, не второе подключение.
		conn(account.Ref{CompanyID: accounttest.Ref("other").CompanyID, AccountID: ok.AccountID}, ""),
	}, ","), "")

	cabinets, rejected, err := fetch(t, body)

	require.NoError(t, err)
	require.Len(t, cabinets, 1)
	assert.Equal(t, ok, cabinets[0].Account)
	assert.Equal(t, []string{
		"not-a-uuid", accounttest.Ref("no-company").AccountID, accounttest.Ref("wb").AccountID,
		accounttest.Ref("bad-date").AccountID, accounttest.Ref("empty-key").AccountID,
		accounttest.Ref("no-credentials").AccountID, ok.AccountID,
	}, rejected, "дубль marketplace_account_id тоже отвергается")
}

func TestClientFetchFailsOnNon200(t *testing.T) {
	for _, status := range []int{401, 403, 422, 500, 503} {
		srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			w.WriteHeader(status)
		}))
		_, _, err := NewClient(srv.URL, token, srv.Client()).Fetch(context.Background())
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

func (f *scriptedFetcher) Fetch(context.Context) ([]Cabinet, []string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	r := f.results[min(f.calls, len(f.results)-1)]
	f.calls++
	return r.cabinets, nil, r.err
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

func newCache(f Fetcher) (*Cache, *clock, *bytes.Buffer) {
	logs := &bytes.Buffer{}
	clk := &clock{now: time.Date(2026, 10, 17, 12, 0, 0, 0, time.UTC)}
	return NewCache(f, slog.New(slog.NewJSONHandler(logs, nil)), clk.Now), clk, logs
}

func exampleCabinets(t *testing.T) []Cabinet {
	t.Helper()
	cabinets, _, err := fetch(t, example(t))
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
	f := fetcherFunc(func(context.Context) ([]Cabinet, []string, error) {
		calls.Add(1)
		return []Cabinet{{Account: accounttest.Ref("ref-a")}}, nil, nil
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

type fetcherFunc func(context.Context) ([]Cabinet, []string, error)

func (f fetcherFunc) Fetch(ctx context.Context) ([]Cabinet, []string, error) { return f(ctx) }

func TestAllowlistKeepsOnlyListedCabinets(t *testing.T) {
	cabinets := exampleCabinets(t)
	for name, allow := range map[string][]string{
		"by marketplace_account_id": {refA.AccountID},
		"by company_id":             {" " + refA.CompanyID + " ", ""},
	} {
		t.Run(name, func(t *testing.T) {
			cache, _, _ := newCache(&scriptedFetcher{results: []fetchResult{{cabinets: cabinets}}})
			require.NoError(t, cache.WithAllowlist(allow).Refresh(context.Background()))
			list := cache.List()
			require.Len(t, list, 1)
			assert.Equal(t, refA, list[0].Account)
			_, ok := cache.Get(refB)
			assert.False(t, ok)
		})
	}

	cache, _, _ := newCache(&scriptedFetcher{results: []fetchResult{{cabinets: cabinets}}})
	require.NoError(t, cache.WithAllowlist(nil).Refresh(context.Background()))
	assert.Len(t, cache.List(), 2, "пустой список — все подключения (меняется на этапе 5)")
}
