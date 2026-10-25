package ozon

import (
	"context"
	"net/http"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozonfixture"
)

// Ответы кабинета Conwix (api/tests/Fixtures/Marketplace/ozon): страница
// уходит в raw побайтово, курсор и признак конца читаются из боевой формы.

func TestAccrualByDayRealPageIsStoredAsIs(t *testing.T) {
	body := ozonfixture.Read(t, "finance-accrual-by-day-2026-07.json")
	var requests []map[string]any
	handler := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests = append(requests, decodeBody(t, r))
		_, _ = w.Write(body)
	})

	var pages []Page
	err := clientFor(t, handler).FetchAccrualByDay(context.Background(), testRef, testCreds, "2026-07-01", "", 1, collect(&pages))

	require.NoError(t, err)
	require.Len(t, pages, 1, `last_id "" — конец дня`)
	assert.Equal(t, body, pages[0].Body)
	assert.Empty(t, pages[0].Cursor)
	assert.Equal(t, []map[string]any{{"date": "2026-07-01", "last_id": ""}}, requests, "тело — как у PHP (OzonAccrualByDayClient): date и last_id")
}

// Две страницы FBO v3 за 19.10.2026: курсор первой ведёт ко второй, has_next
// false — конец. Тело запроса — как у PHP (OzonPostingFboListClient, ADR-042):
// translit false, аналитика и финансы, лимит 100.
func TestPostingListFBORealPagesFollowCursorWithPHPRequest(t *testing.T) {
	p1 := ozonfixture.Read(t, "posting-fbo-list-v3-2026-10-19-page-001.json")
	p2 := ozonfixture.Read(t, "posting-fbo-list-v3-2026-10-19-page-002.json")
	const cursor = "xLazrQ0AAAAAAND/n/8BAAAAAACUwpK2+gIAAAAA"
	srv := &postingServer{pages: map[string][]byte{"": p1, cursor: p2}}

	var pages []Page
	err := clientFor(t, srv.handler(t)).FetchPostingList(context.Background(), testRef, testCreds, SchemeFBO, dayWindow(), "", 1, collect(&pages))

	require.NoError(t, err)
	require.Len(t, pages, 2)
	assert.Equal(t, p1, pages[0].Body)
	assert.Equal(t, p2, pages[1].Body)
	assert.Equal(t, []string{cursor, ""}, []string{pages[0].Cursor, pages[1].Cursor})
	for i, body := range srv.bodies {
		assert.Equal(t, false, body["translit"], "page %d", i)
		assert.Equal(t, map[string]any{"analytics_data": true, "financial_data": true}, body["with"], "page %d", i)
		assert.InDelta(t, 100, body["limit"], 0, "page %d", i)
		assert.Equal(t, map[string]any{"since": "2026-10-18T21:00:00Z", "to": "2026-10-19T21:00:00Z"}, body["filter"], "page %d", i)
	}
	assert.Empty(t, srv.bodies[0]["cursor"], "первая страница — cursor пустой строкой, как у PHP")
	assert.Contains(t, srv.bodies[0], "cursor")
	assert.Equal(t, cursor, srv.bodies[1]["cursor"])
}

// Каталог 13.08.2026: полная страница с непустым last_id, затем пустая —
// конец; номера товаров для карточек — из боевой страницы.
func TestProductListRealPageThenEmptyPage(t *testing.T) {
	list := ozonfixture.Read(t, "product-list-2026-08-13.json")
	handler, bodies := productServer(t, EndpointProductList, func(body map[string]any) (int, string) {
		if body["last_id"] == "" {
			return http.StatusOK, string(list)
		}
		return http.StatusOK, productListBody("", 62)
	})

	var pages []Page
	err := clientFor(t, handler).FetchProductList(context.Background(), testRef, testCreds, "", 1, collect(&pages))

	require.NoError(t, err)
	require.Len(t, pages, 2)
	assert.Equal(t, list, pages[0].Body)
	assert.Equal(t, "WzQ3MDI5ODQwNjMsNDcwMjk4NDA2M10=", (*bodies)[1]["last_id"])
	ids, total, err := ProductListIDs(pages[0].Body)
	require.NoError(t, err)
	assert.Len(t, ids, 62)
	assert.Equal(t, int64(62), total)
}

// Карточки 13.08.2026 по номерам из каталога того же дня: каждый запрошенный
// товар в ответе ровно один раз, пропусков нет.
func TestProductInfoRealPageMatchesRealListIDs(t *testing.T) {
	ids, _, err := ProductListIDs(ozonfixture.Read(t, "product-list-2026-08-13.json"))
	require.NoError(t, err)
	info := ozonfixture.Read(t, "product-info-list-2026-08-13.json")
	handler, bodies := productServer(t, EndpointProductInfoList, func(map[string]any) (int, string) { return http.StatusOK, string(info) })

	page, missing, err := clientFor(t, handler).FetchProductInfo(context.Background(), testRef, testCreds, ids)

	require.NoError(t, err)
	assert.Equal(t, info, page.Body)
	assert.Zero(t, missing)
	assert.Len(t, (*bodies)[0]["product_id"], 62)
}
