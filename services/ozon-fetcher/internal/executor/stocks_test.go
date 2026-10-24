package executor

import (
	"context"
	"fmt"
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

func (f *fakeOzon) FetchStocks(ctx context.Context, _ account.Ref, creds ozon.Credentials, cursor string, firstPageNo int, onPage ozon.PageHandler) error {
	f.creds = append(f.creds, creds)
	f.stockFrom = append(f.stockFrom, cursor)
	if f.productHang {
		<-ctx.Done()
		return ctx.Err()
	}
	for no := firstPageNo; ; no++ {
		p, ok := f.stockPages[cursor]
		if !ok {
			return f.stockErr
		}
		p.No = no
		if err := onPage(p); err != nil {
			return err
		}
		if p.Cursor == "" {
			return nil
		}
		cursor = p.Cursor
	}
}

var stocksCabinet = func() connections.Cabinet {
	c := cabinet
	c.ResourceTypes = []string{ResourceStocks}
	return c
}()

func stocksPage(total int, cursor string, ids ...int64) ozon.Page {
	items := make([]string, len(ids))
	for i, id := range ids {
		items[i] = fmt.Sprintf(`{"product_id":%d,"offer_id":"A-%d","stocks":[{"type":"fbo","present":1,"reserved":0,"sku":%d},{"type":"fbs","present":2,"reserved":0,"sku":%d}]}`, id, id, id*10, id*10)
	}
	return page(fmt.Sprintf(`{"items":[%s],"total":%d,"cursor":%q,"total_items":%d}`, strings.Join(items, ","), total, cursor, total), cursor)
}

// msk — момент по часам МСК.
func msk(t *testing.T, value string) time.Time {
	t.Helper()
	v, err := time.ParseInLocation("2006-01-02 15:04", value, DefaultConfig().Location)
	require.NoError(t, err)
	return v
}

func TestStocksStoresEveryPageAndLogsCounts(t *testing.T) {
	o := &fakeOzon{stockPages: map[string]ozon.Page{
		"":   stocksPage(3, "C1", 5, 7),
		"C1": stocksPage(3, "C2", 9),
		"C2": stocksPage(3, ""),
	}}
	h := newHarness(o)

	res := h.exec.Run(context.Background(), task(ResourceStocks, today), stocksCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, []string{"C1", "C2", ""}, h.tasks.cursors)
	assert.Equal(t, ResourceStocks, h.raw.puts[0].ResourceType)
	assert.Contains(t, h.logs.String(), `"msg":"stocks snapshot read"`)
	assert.Contains(t, h.logs.String(), `"items":3,"skus":3,"total":3`)
	assert.NotContains(t, h.logs.String(), `"level":"WARN"`)
}

func TestStocksEmptySnapshotLogsZeroTotal(t *testing.T) {
	o := &fakeOzon{stockPages: map[string]ozon.Page{"": stocksPage(0, "")}}
	h := newHarness(o)

	res := h.exec.Run(context.Background(), task(ResourceStocks, today), stocksCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Contains(t, h.logs.String(), `"items":0,"skus":0,"total":0`)
	assert.NotContains(t, h.logs.String(), `"level":"WARN"`)
}

func TestStocksCountMismatchIsWarned(t *testing.T) {
	o := &fakeOzon{stockPages: map[string]ozon.Page{"": stocksPage(5, "C1", 5), "C1": stocksPage(5, "")}}
	h := newHarness(o)

	res := h.exec.Run(context.Background(), task(ResourceStocks, today), stocksCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Contains(t, h.logs.String(), `"msg":"stocks snapshot: items differ from total"`)
}

func TestStocksResumesFromSavedCursor(t *testing.T) {
	o := &fakeOzon{stockPages: map[string]ozon.Page{"C1": stocksPage(2, "")}}
	h := newHarness(o)
	tk := task(ResourceStocks, today)
	tk.Cursor, tk.NextPageNo, tk.PageHashes = "C1", 2, []string{"h1"}

	res := h.exec.Run(context.Background(), tk, stocksCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, []string{"C1"}, o.stockFrom)
	assert.Equal(t, 2, h.tasks.pages[0].PageNo)
}

func TestStocksStaleCursorRestartsFromFirstPage(t *testing.T) {
	o := &fakeOzon{stockPages: map[string]ozon.Page{}, stockErr: &ozon.APIError{Class: ozon.ClassInvalidRequest, Endpoint: ozon.EndpointStocks, Status: 400}}
	h := newHarness(o)
	tk := task(ResourceStocks, today)
	tk.Cursor, tk.NextPageNo, tk.PageHashes = "C1", 2, []string{"h1"}

	res := h.exec.Run(context.Background(), tk, stocksCabinet, "trace")

	assert.Equal(t, "stale_cursor", res.Outcome)
	assert.False(t, h.tasks.finished.KeepCursor)
}

func TestStocksBeforeWindowWaitsWithoutRequest(t *testing.T) {
	cases := map[string]struct {
		resource, period, now, wait string
	}{
		"daily before 05:00": {ResourceStocks, "2026-10-17", "2026-10-17 04:59", "2026-10-17 05:00"},
	}
	for name, tc := range cases {
		t.Run(name, func(t *testing.T) {
			o := &fakeOzon{stockPages: map[string]ozon.Page{"": stocksPage(0, "")}}
			h := newHarness(o)
			h.exec.now = func() time.Time { return msk(t, tc.now) }

			res := h.exec.Run(context.Background(), task(tc.resource, tc.period), stocksCabinet, "trace")

			assert.Equal(t, "snapshot_early", res.Outcome)
			assert.Equal(t, store.StatusPending, h.tasks.finished.Status)
			assert.True(t, h.tasks.finished.NextRunAt.Equal(msk(t, tc.wait)))
			assert.Empty(t, o.stockFrom)
		})
	}
}

func TestStocksSnapshotOfPassedDayIsNotFetched(t *testing.T) {
	o := &fakeOzon{stockPages: map[string]ozon.Page{"": stocksPage(0, "")}}
	h := newHarness(o)
	h.exec.now = func() time.Time { return msk(t, "2026-10-19 00:01") }

	res := h.exec.Run(context.Background(), task(ResourceStocks, "2026-10-18"), stocksCabinet, "trace")

	assert.Equal(t, "snapshot_missed", res.Outcome)
	assert.Equal(t, store.StatusFailedPermanent, h.tasks.finished.Status)
	assert.Empty(t, o.stockFrom)
	assert.NotContains(t, h.logs.String(), `"level":"ERROR"`)
}

func TestStocksLoadCrossingMidnightIsMissed(t *testing.T) {
	o := &fakeOzon{productHang: true}
	h := newHarness(o)
	h.exec.now = func() time.Time { return time.Date(2026, 10, 17, 20, 59, 59, 950_000_000, time.UTC) }

	res := h.exec.Run(context.Background(), task(ResourceStocks, today), stocksCabinet, "trace")

	assert.Equal(t, "snapshot_missed", res.Outcome)
	assert.Empty(t, h.tasks.pages)
}

func TestStocksDisabledDoNotCallOzon(t *testing.T) {
	o := &fakeOzon{stockPages: map[string]ozon.Page{"": stocksPage(0, "")}}
	h := newHarness(o)

	res := h.exec.Run(context.Background(), task(ResourceStocks, today), cabinet, "trace")

	assert.Equal(t, "resource_disabled", res.Outcome)
	assert.Empty(t, o.stockFrom)
}
