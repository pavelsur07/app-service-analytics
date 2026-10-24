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

func productServer(t *testing.T, endpoint string, respond func(body map[string]any) (int, string)) (http.Handler, *[]map[string]any) {
	var bodies []map[string]any
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		assert.Equal(t, endpoint, r.URL.Path)
		body := decodeBody(t, r)
		bodies = append(bodies, body)
		status, out := respond(body)
		w.WriteHeader(status)
		_, _ = w.Write([]byte(out))
	}), &bodies
}

func productListBody(lastID string, total int, ids ...int) string {
	items := make([]string, len(ids))
	for i, id := range ids {
		items[i] = fmt.Sprintf(`{"product_id":%d,"offer_id":"A-%d","sku":%d,"archived":false}`, id, id, id*10)
	}
	return fmt.Sprintf(`{"result":{"items":[%s],"total":%d,"last_id":%q,"total_items":%d}}`, strings.Join(items, ","), total, lastID, total)
}

// Живая форма 21.10.2026: последняя полная страница — с непустым last_id,
// конец — пустая страница с last_id "".
func TestFetchProductListWalksLastIDUntilEmptyPage(t *testing.T) {
	handler, bodies := productServer(t, EndpointProductList, func(body map[string]any) (int, string) {
		switch body["last_id"] {
		case "":
			return http.StatusOK, productListBody("L1", 3, 1, 2)
		case "L1":
			return http.StatusOK, productListBody("L2", 3, 3)
		default:
			return http.StatusOK, productListBody("", 3)
		}
	})
	var pages []Page
	err := clientFor(t, handler).FetchProductList(context.Background(), testRef, testCreds, "", 1, func(p Page) error {
		pages = append(pages, p)
		return nil
	})

	require.NoError(t, err)
	require.Len(t, pages, 3)
	assert.Equal(t, []string{"L1", "L2", ""}, []string{pages[0].Cursor, pages[1].Cursor, pages[2].Cursor})
	assert.Equal(t, []int{1, 2, 3}, []int{pages[0].No, pages[1].No, pages[2].No})
	assert.Equal(t, map[string]any{"filter": map[string]any{"visibility": "ALL"}, "last_id": "", "limit": float64(ProductListPageLimit)}, (*bodies)[0])
	assert.Equal(t, "L2", (*bodies)[2]["last_id"])

	ids, total, err := ProductListIDs(pages[0].Body)
	require.NoError(t, err)
	assert.Equal(t, []int64{1, 2}, ids)
	assert.Equal(t, int64(3), total)
}

func TestFetchProductListResumesFromLastID(t *testing.T) {
	handler, bodies := productServer(t, EndpointProductList, func(map[string]any) (int, string) {
		return http.StatusOK, productListBody("", 3)
	})
	var pages []Page
	err := clientFor(t, handler).FetchProductList(context.Background(), testRef, testCreds, "L2", 3, func(p Page) error {
		pages = append(pages, p)
		return nil
	})
	require.NoError(t, err)
	assert.Equal(t, "L2", (*bodies)[0]["last_id"])
	assert.Equal(t, 3, pages[0].No)
}

func TestFetchProductListEmptyPageEndsEvenWithLastID(t *testing.T) {
	handler, _ := productServer(t, EndpointProductList, func(map[string]any) (int, string) {
		return http.StatusOK, productListBody("L9", 0)
	})
	var pages []Page
	err := clientFor(t, handler).FetchProductList(context.Background(), testRef, testCreds, "", 1, func(p Page) error {
		pages = append(pages, p)
		return nil
	})
	require.NoError(t, err)
	require.Len(t, pages, 1)
	assert.Empty(t, pages[0].Cursor)
}

func TestFetchProductListRejectsBrokenPages(t *testing.T) {
	cases := map[string]string{
		"no result":             `{"items":[]}`,
		"no items":              `{"result":{"total":1,"last_id":""}}`,
		"no last_id":            `{"result":{"items":[{"product_id":1}],"total":1}}`,
		"no total":              `{"result":{"items":[{"product_id":1}],"last_id":""}}`,
		"neg total":             `{"result":{"items":[],"total":-1,"last_id":""}}`,
		"repeated id":           productListBody("L0", 2, 1),
		"items without last_id": productListBody("", 1500, 1, 2),
	}
	for name, body := range cases {
		t.Run(name, func(t *testing.T) {
			handler, _ := productServer(t, EndpointProductList, func(map[string]any) (int, string) { return http.StatusOK, body })
			err := clientFor(t, handler).FetchProductList(context.Background(), testRef, testCreds, "L0", 2, func(Page) error { return nil })
			var apiErr *APIError
			require.ErrorAs(t, err, &apiErr)
			assert.Equal(t, ClassInvalidResponse, apiErr.Class)
		})
	}
}

func TestFetchProductListPageLimitIsAbsolute(t *testing.T) {
	handler, bodies := productServer(t, EndpointProductList, func(map[string]any) (int, string) {
		return http.StatusOK, productListBody("L-next", 1, 1)
	})
	err := clientFor(t, handler).FetchProductList(context.Background(), testRef, testCreds, "L", MaxProductListPages+1, func(Page) error { return nil })
	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ClassInvalidResponse, apiErr.Class)
	assert.Empty(t, *bodies, "предел — до запроса")
}

func TestFetchProductInfoSendsOnlyProductIDsAndCountsMissing(t *testing.T) {
	handler, bodies := productServer(t, EndpointProductInfoList, func(map[string]any) (int, string) {
		return http.StatusOK, `{"items":[{"id":7,"offer_id":"A-7","sku":70},{"id":5,"offer_id":"A-5","sku":50}]}`
	})
	page, missing, err := clientFor(t, handler).FetchProductInfo(context.Background(), testRef, testCreds, []int64{5, 6, 7})

	require.NoError(t, err)
	assert.Equal(t, []map[string]any{{"product_id": []any{float64(5), float64(6), float64(7)}}}, *bodies)
	assert.Equal(t, 1, missing, "удалённый товар Ozon молча пропускает")
	assert.Contains(t, string(page.Body), `"id":7`)
}

func TestFetchProductInfoRejectsBadInputWithoutRequest(t *testing.T) {
	client := clientFor(t, http.HandlerFunc(func(http.ResponseWriter, *http.Request) { t.Fatal("no request expected") }))
	tooMany := make([]int64, MaxProductInfoIDs+1)
	for i := range tooMany {
		tooMany[i] = int64(i + 1)
	}
	for name, ids := range map[string][]int64{"none": nil, "too many": tooMany, "duplicate": {1, 1}, "zero": {0}} {
		_, _, err := client.FetchProductInfo(context.Background(), testRef, testCreds, ids)
		require.Error(t, err, name)
	}
}

func TestFetchProductInfoResponseMustComeFromRequest(t *testing.T) {
	cases := map[string]string{
		"no array": `{"result":{"items":[]}}`,
		"foreign":  `{"items":[{"id":5},{"id":99}]}`,
		"repeated": `{"items":[{"id":5},{"id":5}]}`,
		"no id":    `{"items":[{"offer_id":"A"}]}`,
	}
	for name, body := range cases {
		t.Run(name, func(t *testing.T) {
			handler, _ := productServer(t, EndpointProductInfoList, func(map[string]any) (int, string) { return http.StatusOK, body })
			_, _, err := clientFor(t, handler).FetchProductInfo(context.Background(), testRef, testCreds, []int64{5, 6})
			var apiErr *APIError
			require.ErrorAs(t, err, &apiErr)
			assert.Equal(t, ClassInvalidResponse, apiErr.Class)
		})
	}
}

func TestProductEndpointsErrorClasses(t *testing.T) {
	for status, tc := range map[int]struct {
		body  string
		class Class
	}{
		http.StatusBadRequest:      {`{"code":3,"message":"value must contain no more than 1000 item(s)"}`, ClassInvalidRequest},
		http.StatusTooManyRequests: {`{"code":8,"message":"too many requests"}`, ClassRateLimit},
	} {
		list, _ := productServer(t, EndpointProductList, func(map[string]any) (int, string) { return status, tc.body })
		err := clientFor(t, list).FetchProductList(context.Background(), testRef, testCreds, "", 1, func(Page) error { return nil })
		var apiErr *APIError
		require.ErrorAs(t, err, &apiErr)
		assert.Equal(t, tc.class, apiErr.Class)

		info, _ := productServer(t, EndpointProductInfoList, func(map[string]any) (int, string) { return status, tc.body })
		_, _, err = clientFor(t, info).FetchProductInfo(context.Background(), testRef, testCreds, []int64{1})
		require.ErrorAs(t, err, &apiErr)
		assert.Equal(t, tc.class, apiErr.Class)
	}
	obsolete, _ := productServer(t, EndpointProductInfoList, func(map[string]any) (int, string) {
		return http.StatusBadRequest, `{"code":9,"message":"obsolete method cannot be used"}`
	})
	_, _, err := clientFor(t, obsolete).FetchProductInfo(context.Background(), testRef, testCreds, []int64{1})
	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ClassObsoleteEndpoint, apiErr.Class)
}
