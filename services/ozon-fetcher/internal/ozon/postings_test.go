package ozon

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"sync"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

var msk = time.FixedZone("MSK", 3*60*60)

func dayWindow() PostingWindow {
	from := time.Date(2026, 10, 19, 0, 0, 0, 0, msk)
	return PostingWindow{From: from, To: from.AddDate(0, 0, 1)}
}

// postingPage — страница новой формы: postings, has_next, cursor на верхнем уровне.
func postingPage(n int, hasNext bool, cursor string) []byte {
	postings := "["
	for i := range n {
		if i > 0 {
			postings += ","
		}
		postings += fmt.Sprintf(`{"posting_number":"P-%d"}`, i)
	}
	postings += "]"
	return fmt.Appendf(nil, `{"postings":%s,"has_next":%t,"cursor":%q}`, postings, hasNext, cursor)
}

type postingServer struct {
	mu     sync.Mutex
	pages  map[string][]byte // курсор запроса → ответ
	bodies []map[string]any
	paths  []string
}

func (s *postingServer) handler(t *testing.T) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body := decodeBody(t, r)
		cursor, _ := body["cursor"].(string)
		s.mu.Lock()
		s.bodies = append(s.bodies, body)
		s.paths = append(s.paths, r.URL.Path)
		page, ok := s.pages[cursor]
		s.mu.Unlock()
		if !ok {
			w.WriteHeader(http.StatusBadRequest)
			_, _ = w.Write([]byte(`{"code":3,"message":"unexpected cursor"}`))
			return
		}
		_, _ = w.Write(page)
	})
}

func collect(pages *[]Page) PageHandler {
	return func(p Page) error {
		*pages = append(*pages, p)
		return nil
	}
}

func TestFetchPostingListWalksCursorUntilHasNextFalse(t *testing.T) {
	srv := &postingServer{pages: map[string][]byte{
		"":   postingPage(100, true, "c1"),
		"c1": postingPage(100, true, "c2"),
		"c2": postingPage(7, false, ""),
	}}
	client := clientFor(t, srv.handler(t))

	var got []Page
	err := client.FetchPostingList(context.Background(), testRef, testCreds, SchemeFBO, dayWindow(), "", 1, collect(&got))

	require.NoError(t, err)
	require.Len(t, got, 3)
	assert.Equal(t, []int{1, 2, 3}, []int{got[0].No, got[1].No, got[2].No})
	assert.Equal(t, []string{"c1", "c2", ""}, []string{got[0].Cursor, got[1].Cursor, got[2].Cursor})
	assert.Equal(t, postingPage(7, false, ""), got[2].Body)
	assert.Equal(t, []string{EndpointPostingFBOList, EndpointPostingFBOList, EndpointPostingFBOList}, srv.paths)
}

func TestFetchPostingListRequestHasCursorPaginationOnly(t *testing.T) {
	srv := &postingServer{pages: map[string][]byte{"": postingPage(100, true, "c1"), "c1": postingPage(1, false, "")}}
	client := clientFor(t, srv.handler(t))

	var got []Page
	require.NoError(t, client.FetchPostingList(context.Background(), testRef, testCreds, SchemeFBS, dayWindow(), "", 1, collect(&got)))

	require.Len(t, srv.bodies, 2)
	assert.Equal(t, EndpointPostingFBSList, srv.paths[0])
	for i, body := range srv.bodies {
		assert.InDelta(t, 100, body["limit"], 0, "page %d", i)
		assert.NotContains(t, body, "offset", "page %d", i)
		assert.NotContains(t, body, "dir", "page %d", i)
		assert.Equal(t, "ASC", body["sort_dir"])
		assert.Equal(t, map[string]any{"since": "2026-10-18T21:00:00Z", "to": "2026-10-19T21:00:00Z"}, body["filter"])
		assert.Equal(t, map[string]any{"analytics_data": true, "financial_data": true}, body["with"])
	}
	assert.NotContains(t, srv.bodies[0], "cursor")
	assert.Equal(t, "c1", srv.bodies[1]["cursor"])
}

func TestFetchPostingListFirstPageAndSilenceIsAnError(t *testing.T) {
	cases := map[string][]byte{
		"has_next without cursor":           []byte(`{"postings":[{"posting_number":"P-1"}],"has_next":true,"cursor":""}`),
		"full page without any marker":      postingPage100WithoutMarkers(),
		"not a postings array":              []byte(`{"result":{"items":[]}}`),
		"has_next without cursor in result": []byte(`{"result":{"postings":[],"has_next":true}}`),
	}
	for name, page := range cases {
		t.Run(name, func(t *testing.T) {
			srv := &postingServer{pages: map[string][]byte{"": page}}
			client := clientFor(t, srv.handler(t))

			var got []Page
			err := client.FetchPostingList(context.Background(), testRef, testCreds, SchemeFBO, dayWindow(), "", 1, collect(&got))

			var apiErr *APIError
			require.ErrorAs(t, err, &apiErr)
			assert.Equal(t, ClassInvalidResponse, apiErr.Class)
			assert.Empty(t, got, "a page that failed validation is not stored")
		})
	}
}

// postingPage100WithoutMarkers — так выглядит ответ, если Ozon молча отдал
// первые 100 отправлений окна: полная страница без has_next и cursor.
func postingPage100WithoutMarkers() []byte {
	page := postingPage(100, false, "")
	var shape map[string]any
	if err := json.Unmarshal(page, &shape); err != nil {
		panic(err)
	}
	out, _ := json.Marshal(map[string]any{"postings": shape["postings"]})
	return out
}

func TestFetchPostingListAcceptsResultEnvelopeAndMissingHasNext(t *testing.T) {
	srv := &postingServer{pages: map[string][]byte{
		"":   []byte(`{"result":{"postings":[{"posting_number":"P-1"}],"has_next":true,"cursor":"c1"}}`),
		"c1": []byte(`{"postings":[{"posting_number":"P-2"}],"cursor":"c2"}`),
		"c2": []byte(`{"postings":[],"cursor":"c3"}`),
	}}
	client := clientFor(t, srv.handler(t))

	var got []Page
	require.NoError(t, client.FetchPostingList(context.Background(), testRef, testCreds, SchemeFBS, dayWindow(), "", 1, collect(&got)))

	require.Len(t, got, 3, "без has_next продолжение — по курсору, пустая страница — конец")
	assert.Equal(t, "", got[2].Cursor)
}

func TestFetchPostingListRepeatedCursorIsAnError(t *testing.T) {
	srv := &postingServer{pages: map[string][]byte{"": postingPage(100, true, "c1"), "c1": postingPage(100, true, "c1")}}
	client := clientFor(t, srv.handler(t))

	var got []Page
	err := client.FetchPostingList(context.Background(), testRef, testCreds, SchemeFBO, dayWindow(), "", 1, collect(&got))

	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ClassInvalidResponse, apiErr.Class)
	assert.Contains(t, err.Error(), "repeated pagination cursor")
	assert.Len(t, got, 1)
}

func TestFetchPostingListPageLimit(t *testing.T) {
	pages := map[string][]byte{}
	for i := range MaxPostingPages + 1 {
		cur := ""
		if i > 0 {
			cur = fmt.Sprintf("c%d", i)
		}
		// Одно отправление на странице: продолжение задаёт has_next, а тест
		// из 1000 запросов не раздувает память под -race.
		pages[cur] = postingPage(1, true, fmt.Sprintf("c%d", i+1))
	}
	srv := &postingServer{pages: pages}
	client := clientFor(t, srv.handler(t))

	var got []Page
	err := client.FetchPostingList(context.Background(), testRef, testCreds, SchemeFBO, dayWindow(), "", 1, collect(&got))

	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Contains(t, err.Error(), "more than 1000 pages")
	assert.Len(t, got, MaxPostingPages)
}

func TestFetchPostingListPageLimitCountsPagesOfWindowAcrossAttempts(t *testing.T) {
	// Прежняя попытка сохранила MaxPostingPages-1 страниц; продолжение не получает новых.
	srv := &postingServer{pages: map[string][]byte{"c999": postingPage(100, true, "c1000"), "c1000": postingPage(100, true, "c1001")}}
	client := clientFor(t, srv.handler(t))

	var got []Page
	err := client.FetchPostingList(context.Background(), testRef, testCreds, SchemeFBO, dayWindow(), "c999", MaxPostingPages, collect(&got))

	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Contains(t, err.Error(), "more than 1000 pages")
	assert.Len(t, got, 1, "последняя разрешённая страница сохранена, следующая не запрашивается")
}

func TestFetchPostingListResumesFromCursor(t *testing.T) {
	srv := &postingServer{pages: map[string][]byte{"c2": postingPage(3, false, "")}}
	client := clientFor(t, srv.handler(t))

	var got []Page
	require.NoError(t, client.FetchPostingList(context.Background(), testRef, testCreds, SchemeFBO, dayWindow(), "c2", 3, collect(&got)))

	require.Len(t, got, 1)
	assert.Equal(t, 3, got[0].No)
	assert.Equal(t, "c2", srv.bodies[0]["cursor"])
}

func TestFetchPostingListStopsOnHandlerError(t *testing.T) {
	srv := &postingServer{pages: map[string][]byte{"": postingPage(100, true, "c1"), "c1": postingPage(1, false, "")}}
	client := clientFor(t, srv.handler(t))
	stop := errors.New("store failed")

	err := client.FetchPostingList(context.Background(), testRef, testCreds, SchemeFBO, dayWindow(), "", 1, func(Page) error { return stop })

	require.ErrorIs(t, err, stop)
	assert.Len(t, srv.bodies, 1, "следующая страница не запрашивается, пока не сохранена текущая")
}

func TestFetchPostingListErrorClasses(t *testing.T) {
	cases := []struct {
		name   string
		status int
		body   string
		class  Class
	}{
		{"obsolete method", http.StatusBadRequest, `{"code":9,"message":"obsolete method cannot be used"}`, ClassObsoleteEndpoint},
		{"limit out of range", http.StatusBadRequest, `{"code":3,"message":"Request validation error: invalid PostingFboListRequest.Limit: value must be inside range (0, 100]"}`, ClassInvalidRequest},
		{"rate limit", http.StatusTooManyRequests, `{"code":8,"message":"too many requests"}`, ClassRateLimit},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			client := clientFor(t, http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
				w.WriteHeader(tc.status)
				_, _ = w.Write([]byte(tc.body))
			}))

			err := client.FetchPostingList(context.Background(), testRef, testCreds, SchemeFBO, dayWindow(), "", 1, func(Page) error { return nil })

			var apiErr *APIError
			require.ErrorAs(t, err, &apiErr)
			assert.Equal(t, tc.class, apiErr.Class)
			assert.Equal(t, EndpointPostingFBOList, apiErr.Endpoint)
			if tc.status == http.StatusBadRequest {
				assert.Contains(t, err.Error(), apiErr.Message, "текст ошибки Ozon попадает в журнал")
				assert.NotEmpty(t, apiErr.Message)
			}
			assert.NotContains(t, err.Error(), testAPIKey)
		})
	}
}

func TestFetchPostingListRejectsBadInput(t *testing.T) {
	client := clientFor(t, http.HandlerFunc(func(http.ResponseWriter, *http.Request) { t.Fatal("no request expected") }))
	w := dayWindow()

	require.Error(t, client.FetchPostingList(context.Background(), testRef, testCreds, "rfbs", w, "", 1, collect(new([]Page))))
	require.Error(t, client.FetchPostingList(context.Background(), testRef, testCreds, SchemeFBO, PostingWindow{From: w.To, To: w.From}, "", 1, collect(new([]Page))))
}
