package ozon

import (
	"context"
	"fmt"
	"net/http"
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func stocksBody(cursor string, total int, ids ...int) string {
	items := make([]string, len(ids))
	for i, id := range ids {
		items[i] = fmt.Sprintf(`{"product_id":%d,"offer_id":"A-%d","stocks":[{"type":"fbo","present":3,"reserved":0,"sku":%d,"shipment_type":"SHIPMENT_TYPE_GENERAL","warehouse_ids":[]},{"type":"fbs","present":1,"reserved":0,"sku":%d}]}`, id, id, id*10, id*10)
	}
	return fmt.Sprintf(`{"items":[%s],"total":%d,"cursor":%q,"total_items":%d}`, strings.Join(items, ","), total, cursor, total)
}

// Живая форма 22.10.2026: без result, курсор cursor; последняя полная
// страница — с непустым курсором, конец — пустая страница с cursor "".
func TestFetchStocksWalksCursorUntilEmptyPage(t *testing.T) {
	handler, bodies := productServer(t, EndpointStocks, func(body map[string]any) (int, string) {
		switch body["cursor"] {
		case nil:
			return http.StatusOK, stocksBody("C1", 3, 1, 2)
		case "C1":
			return http.StatusOK, stocksBody("C2", 3, 3)
		default:
			return http.StatusOK, stocksBody("", 3)
		}
	})
	var pages []Page
	err := clientFor(t, handler).FetchStocks(context.Background(), testRef, testCreds, "", 1, func(p Page) error {
		pages = append(pages, p)
		return nil
	})

	require.NoError(t, err)
	require.Len(t, pages, 3)
	assert.Equal(t, []string{"C1", "C2", ""}, []string{pages[0].Cursor, pages[1].Cursor, pages[2].Cursor})
	assert.Equal(t, []int{1, 2, 3}, []int{pages[0].No, pages[1].No, pages[2].No})
	assert.Equal(t, map[string]any{"filter": map[string]any{"visibility": "ALL"}, "limit": float64(StocksPageLimit)}, (*bodies)[0], "первая страница — без курсора")
	assert.Equal(t, "C2", (*bodies)[2]["cursor"])

	page, err := ParseStocksPage(pages[0].Body)
	require.NoError(t, err)
	assert.Equal(t, 2, page.Items)
	assert.Equal(t, []int64{10, 10, 20, 20}, page.SKUs, "SKU по каждой строке stocks")
	assert.Equal(t, int64(3), page.Total)
}

func TestFetchStocksResumesFromCursor(t *testing.T) {
	handler, bodies := productServer(t, EndpointStocks, func(map[string]any) (int, string) {
		return http.StatusOK, stocksBody("", 3)
	})
	var pages []Page
	err := clientFor(t, handler).FetchStocks(context.Background(), testRef, testCreds, "C2", 3, func(p Page) error {
		pages = append(pages, p)
		return nil
	})
	require.NoError(t, err)
	assert.Equal(t, "C2", (*bodies)[0]["cursor"])
	assert.Equal(t, 3, pages[0].No)
}

func TestFetchStocksEmptyPageEndsEvenWithCursor(t *testing.T) {
	handler, _ := productServer(t, EndpointStocks, func(map[string]any) (int, string) {
		return http.StatusOK, stocksBody("C9", 0)
	})
	var pages []Page
	err := clientFor(t, handler).FetchStocks(context.Background(), testRef, testCreds, "", 1, func(p Page) error {
		pages = append(pages, p)
		return nil
	})
	require.NoError(t, err)
	require.Len(t, pages, 1)
	assert.Empty(t, pages[0].Cursor)
}

func TestFetchStocksRejectsBrokenPages(t *testing.T) {
	cases := map[string]string{
		"not object":           `[]`,
		"no items":             `{"total":1,"cursor":""}`,
		"no cursor":            `{"items":[{"product_id":1,"stocks":[]}],"total":1}`,
		"no total":             `{"items":[{"product_id":1,"stocks":[]}],"cursor":""}`,
		"neg total":            `{"items":[],"total":-1,"cursor":""}`,
		"item without stocks":  `{"items":[{"product_id":1}],"total":1,"cursor":"C5"}`,
		"repeated cursor":      stocksBody("C0", 2, 1),
		"items without cursor": stocksBody("", 1500, 1, 2),
	}
	for name, body := range cases {
		t.Run(name, func(t *testing.T) {
			handler, _ := productServer(t, EndpointStocks, func(map[string]any) (int, string) { return http.StatusOK, body })
			err := clientFor(t, handler).FetchStocks(context.Background(), testRef, testCreds, "C0", 2, func(Page) error { return nil })
			var apiErr *APIError
			require.ErrorAs(t, err, &apiErr)
			assert.Equal(t, ClassInvalidResponse, apiErr.Class)
		})
	}
}

func TestFetchStocksPageLimitIsAbsolute(t *testing.T) {
	handler, bodies := productServer(t, EndpointStocks, func(map[string]any) (int, string) {
		return http.StatusOK, stocksBody("C-next", 1, 1)
	})
	err := clientFor(t, handler).FetchStocks(context.Background(), testRef, testCreds, "C", MaxStocksPages+1, func(Page) error { return nil })
	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ClassInvalidResponse, apiErr.Class)
	assert.Empty(t, *bodies, "предел — до запроса")
}

func TestStocksErrorClasses(t *testing.T) {
	for status, tc := range map[int]struct {
		body  string
		class Class
	}{
		http.StatusBadRequest:      {`{"code":3,"message":"invalid last_id garbage: illegal base64 data at input byte 4"}`, ClassInvalidRequest},
		http.StatusTooManyRequests: {`{"code":8,"message":"too many requests"}`, ClassRateLimit},
	} {
		handler, _ := productServer(t, EndpointStocks, func(map[string]any) (int, string) { return status, tc.body })
		err := clientFor(t, handler).FetchStocks(context.Background(), testRef, testCreds, "", 1, func(Page) error { return nil })
		var apiErr *APIError
		require.ErrorAs(t, err, &apiErr)
		assert.Equal(t, tc.class, apiErr.Class)
	}
}
