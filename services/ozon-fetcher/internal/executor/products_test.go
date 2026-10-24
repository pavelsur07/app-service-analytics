package executor

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/rawstore"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

// today — день снимка в t0 (12:00 МСК 17.10).
const today = "2026-10-17"

func (f *fakeOzon) FetchProductList(ctx context.Context, _ account.Ref, creds ozon.Credentials, lastID string, firstPageNo int, onPage ozon.PageHandler) error {
	f.creds = append(f.creds, creds)
	f.productListFrom = append(f.productListFrom, lastID)
	if f.productHang {
		<-ctx.Done()
		return ctx.Err()
	}
	for no := firstPageNo; ; no++ {
		p, ok := f.productPages[lastID]
		if !ok {
			return f.productListErr
		}
		p.No = no
		if err := onPage(p); err != nil {
			return err
		}
		if p.Cursor == "" {
			return nil
		}
		lastID = p.Cursor
	}
}

// FetchProductInfo — эхо запрошенных id; ошибка — infoErr после infoFailAfter вызовов.
func (f *fakeOzon) FetchProductInfo(ctx context.Context, _ account.Ref, creds ozon.Credentials, ids []int64) (ozon.Page, int, error) {
	f.creds = append(f.creds, creds)
	f.infoCalls = append(f.infoCalls, append([]int64(nil), ids...))
	if f.productHang {
		<-ctx.Done()
		return ozon.Page{}, 0, ctx.Err()
	}
	if f.infoErr != nil && len(f.infoCalls) > f.infoFailAfter {
		return ozon.Page{}, 0, f.infoErr
	}
	return ozon.Page{No: 1, Body: []byte(fmt.Sprintf(`{"items":"%v"}`, ids)), ContentType: "application/json"}, f.infoMissing, nil
}

var productsCabinet = func() connections.Cabinet {
	c := cabinet
	c.ResourceTypes = []string{ResourceProductsList, ResourceProductsInfo}
	return c
}()

func listPage(total int, ids ...int64) string {
	items := make([]string, len(ids))
	for i, id := range ids {
		items[i] = fmt.Sprintf(`{"product_id":%d,"offer_id":"A-%d","sku":%d}`, id, id, id*10)
	}
	return fmt.Sprintf(`{"result":{"items":[%s],"total":%d,"last_id":"x","total_items":%d}}`, strings.Join(items, ","), total, total)
}

// productsHarness — список товаров дня today из страниц pages.
func productsHarness(t *testing.T, o *fakeOzon, pages []string) (*harness, *fakeReader) {
	t.Helper()
	h := newHarness(o)
	reader := &fakeReader{bodies: map[string]string{}}
	h.tasks.content = map[string][]store.RawObject{}
	if pages != nil {
		objs := []store.RawObject{}
		for i, body := range pages {
			uri := fmt.Sprintf("s3://raw/list/p%d", i+1)
			reader.bodies[uri] = body
			objs = append(objs, store.RawObject{PageNo: i + 1, StorageURI: uri, SHA256: sha(body)})
		}
		h.tasks.content[ResourceProductsList+"/"+today] = objs
	}
	h.exec = h.exec.WithRawReader(reader)
	return h, reader
}

func idsKeys(ids []int64) []string {
	out := make([]string, len(ids))
	for i, id := range ids {
		out[i] = fmt.Sprint(id)
	}
	return out
}

func TestProductListStoresEveryPageIncludingTerminalEmpty(t *testing.T) {
	o := &fakeOzon{productPages: map[string]ozon.Page{
		"":   page(listPage(2, 5, 7), "L1"),
		"L1": page(`{"result":{"items":[],"total":2,"last_id":""}}`, ""),
	}}
	h := newHarness(o)

	res := h.exec.Run(context.Background(), task(ResourceProductsList, today), productsCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, []string{"L1", ""}, h.tasks.cursors)
	assert.Equal(t, []int{1, 2}, []int{h.tasks.pages[0].PageNo, h.tasks.pages[1].PageNo})
	assert.Equal(t, ResourceProductsList, h.raw.puts[0].ResourceType)
}

func TestProductListResumesFromSavedLastID(t *testing.T) {
	o := &fakeOzon{productPages: map[string]ozon.Page{"L1": page(`{"result":{"items":[],"total":2,"last_id":""}}`, "")}}
	h := newHarness(o)
	tk := task(ResourceProductsList, today)
	tk.Cursor, tk.NextPageNo, tk.PageHashes = "L1", 2, []string{"h1"}

	res := h.exec.Run(context.Background(), tk, productsCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, []string{"L1"}, o.productListFrom)
	assert.Equal(t, 2, h.tasks.pages[0].PageNo)
}

func TestProductListStaleCursorRestartsFromFirstPage(t *testing.T) {
	o := &fakeOzon{productPages: map[string]ozon.Page{}, productListErr: &ozon.APIError{Class: ozon.ClassInvalidRequest, Endpoint: ozon.EndpointProductList, Status: 400}}
	h := newHarness(o)
	tk := task(ResourceProductsList, today)
	tk.Cursor, tk.NextPageNo, tk.PageHashes = "L1", 2, []string{"h1"}

	res := h.exec.Run(context.Background(), tk, productsCabinet, "trace")

	assert.Equal(t, "stale_cursor", res.Outcome)
	assert.Equal(t, store.StatusFailedRetryable, h.tasks.finished.Status)
	assert.False(t, h.tasks.finished.KeepCursor)

	h2 := newHarness(o)
	res = h2.exec.Run(context.Background(), task(ResourceProductsList, today), productsCabinet, "trace")
	assert.Equal(t, "invalid_request", res.Outcome, "без курсора — постоянная ошибка")
}

func TestProductListStaleCursorWithinAttemptRestarts(t *testing.T) {
	// Первая страница сохранена этой же попыткой, 400 — на продолжении.
	o := &fakeOzon{productPages: map[string]ozon.Page{"": page(listPage(2, 5), "L1")},
		productListErr: &ozon.APIError{Class: ozon.ClassInvalidRequest, Endpoint: ozon.EndpointProductList, Status: 400}}
	h := newHarness(o)

	res := h.exec.Run(context.Background(), task(ResourceProductsList, today), productsCabinet, "trace")

	assert.Equal(t, "stale_cursor", res.Outcome)
	assert.Equal(t, store.StatusFailedRetryable, h.tasks.finished.Status)
	assert.False(t, h.tasks.finished.KeepCursor, "снимок — заново с первой страницы")
}

func TestProductSnapshotOfPassedDayIsNotFetched(t *testing.T) {
	o := &fakeOzon{productPages: map[string]ozon.Page{"": page(listPage(1, 5), "")}}
	for _, resource := range []string{ResourceProductsList, ResourceProductsInfo} {
		h, _ := productsHarness(t, o, []string{listPage(1, 5)})
		tk := task(resource, "2026-10-16")
		tk.Cursor, tk.NextPageNo, tk.PageHashes = "L1", 2, []string{"h1"}

		res := h.exec.Run(context.Background(), tk, productsCabinet, "trace")

		assert.Equal(t, "snapshot_missed", res.Outcome, resource)
		assert.Equal(t, store.StatusFailedPermanent, h.tasks.finished.Status, resource)
		assert.Contains(t, h.logs.String(), `"level":"WARN"`, resource)
		assert.NotContains(t, h.logs.String(), `"level":"ERROR"`, resource)
	}
	h := newHarness(o)
	res := h.exec.Run(context.Background(), task(ResourceProductsList, "2026-10-18"), productsCabinet, "trace")
	assert.Equal(t, "snapshot_missed", res.Outcome, "день из будущего")
	assert.Empty(t, o.productListFrom)
	assert.Empty(t, o.infoCalls)
}

func TestProductSnapshotFullySavedFinishesOnLaterDay(t *testing.T) {
	o := &fakeOzon{}
	h := newHarness(o)
	tk := task(ResourceProductsList, "2026-10-16")
	tk.PageHashes = []string{"h1"} // все страницы сохранены вчера, упала запись итога

	res := h.exec.Run(context.Background(), tk, productsCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Empty(t, o.productListFrom)
}

func TestProductInfoUsesListIDsSortedDedupedInChunks(t *testing.T) {
	var ids []int64
	for i := range 1500 {
		ids = append(ids, int64(3000-i)) // по убыванию — порядок задаёт исполнитель
	}
	pages := []string{listPage(1501, ids[:1000]...), listPage(1501, append(ids[1000:], 3000, 1)...), `{"result":{"items":[],"total":1501,"last_id":""}}`}
	o := &fakeOzon{}
	h, _ := productsHarness(t, o, pages)

	res := h.exec.Run(context.Background(), task(ResourceProductsInfo, today), productsCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	require.Len(t, o.infoCalls, 2)
	assert.Len(t, o.infoCalls[0], 1000)
	assert.Len(t, o.infoCalls[1], 501, "1500 + 1 новый, дубль 3000 один раз")
	assert.Equal(t, int64(1), o.infoCalls[0][0], "по возрастанию числом")
	assert.Equal(t, int64(3000), o.infoCalls[1][500])
	all := append(append([]int64(nil), o.infoCalls[0]...), o.infoCalls[1]...)
	assert.Equal(t, []string{numbersFingerprint(idsKeys(all)) + ":" + fmt.Sprint(o.infoCalls[0][999]), ""}, h.tasks.cursors)
	assert.Equal(t, ResourceProductsInfo, h.raw.puts[0].ResourceType)
	assert.Contains(t, h.logs.String(), "item count differs from total", "1502 строки против total 1501")
	assert.Contains(t, h.logs.String(), `"listed":1502,"unique":1501,"total":1501`, "число товаров снимка — в журнале")

	h2, _ := productsHarness(t, &fakeOzon{}, []string{listPage(2, 1, 2), `{"result":{"items":[],"total":0,"last_id":""}}`})
	assert.Equal(t, "done", h2.exec.Run(context.Background(), task(ResourceProductsInfo, today), productsCabinet, "trace").Outcome)
	assert.NotContains(t, h2.logs.String(), "item count differs", "total концевой пустой страницы не сверяется")
}

func TestProductInfoResumeAndRevisedList(t *testing.T) {
	var ids []int64
	for i := range 1200 {
		ids = append(ids, int64(i+1))
	}
	o := &fakeOzon{}
	h, _ := productsHarness(t, o, []string{listPage(1200, ids...)})
	tk := task(ResourceProductsInfo, today)
	tk.Cursor, tk.NextPageNo, tk.PageHashes = numbersFingerprint(idsKeys(ids))+":1000", 2, []string{"h1"}

	res := h.exec.Run(context.Background(), tk, productsCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	require.Len(t, o.infoCalls, 1, "первая порция не перезапрашивается")
	assert.Equal(t, int64(1001), o.infoCalls[0][0])
	assert.Equal(t, 2, h.tasks.pages[0].PageNo)

	o2 := &fakeOzon{}
	h2, _ := productsHarness(t, o2, []string{listPage(1201, append(ids, 500000)...)})
	res = h2.exec.Run(context.Background(), tk, productsCabinet, "trace")
	assert.Equal(t, "catalog_revised", res.Outcome)
	assert.Empty(t, o2.infoCalls)
	assert.False(t, h2.tasks.finished.KeepCursor)
}

func TestProductInfoFailureKeepsSavedChunkCursor(t *testing.T) {
	var ids []int64
	for i := range 1500 {
		ids = append(ids, int64(i+1))
	}
	o := &fakeOzon{infoErr: &ozon.APIError{Class: ozon.ClassRateLimit, Endpoint: ozon.EndpointProductInfoList, Status: 429}, infoFailAfter: 1}
	h, _ := productsHarness(t, o, []string{listPage(1500, ids...)})

	res := h.exec.Run(context.Background(), task(ResourceProductsInfo, today), productsCabinet, "trace")

	assert.Equal(t, "rate_limit", res.Outcome)
	assert.Equal(t, []string{numbersFingerprint(idsKeys(ids)) + ":1000"}, h.tasks.cursors)
	assert.True(t, h.tasks.finished.KeepCursor)
}

func TestProductInfoWaitsForListOfTheDay(t *testing.T) {
	o := &fakeOzon{}
	h, _ := productsHarness(t, o, nil)

	res := h.exec.Run(context.Background(), task(ResourceProductsInfo, today), productsCabinet, "trace")

	assert.Equal(t, "catalog_not_loaded", res.Outcome)
	assert.Equal(t, store.StatusNotReady, h.tasks.finished.Status)
	assert.Equal(t, t0.Add(DefaultConfig().NotReadyDelay[ResourceProductsInfo]), h.tasks.finished.NextRunAt)
	assert.Empty(t, h.tasks.reloaded, "у каталога нет ревизий до content_pages")
	assert.Empty(t, o.infoCalls)
}

func TestProductInfoEmptyCatalogStoresMarkerWithoutRequest(t *testing.T) {
	o := &fakeOzon{}
	h, _ := productsHarness(t, o, []string{`{"result":{"items":[],"total":0,"last_id":""}}`})

	res := h.exec.Run(context.Background(), task(ResourceProductsInfo, today), productsCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Empty(t, o.infoCalls)
	require.Len(t, h.raw.puts, 1)
	assert.JSONEq(t, `{"items":[]}`, string(h.raw.puts[0].Body))
}

func TestProductInfoMissingProductsAreWarned(t *testing.T) {
	o := &fakeOzon{infoMissing: 2}
	h, _ := productsHarness(t, o, []string{listPage(3, 1, 2, 3)})

	res := h.exec.Run(context.Background(), task(ResourceProductsInfo, today), productsCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Contains(t, h.logs.String(), `"missing":2`)
	assert.Contains(t, h.logs.String(), `"level":"WARN"`)
}

func TestProductsDisabledDoNotCallOzon(t *testing.T) {
	o := &fakeOzon{productPages: map[string]ozon.Page{"": page(listPage(1, 5), "")}}
	for _, resource := range []string{ResourceProductsList, ResourceProductsInfo} {
		h, _ := productsHarness(t, o, []string{listPage(1, 5)})
		res := h.exec.Run(context.Background(), task(resource, today), cabinet, "trace")
		assert.Equal(t, "resource_disabled", res.Outcome, resource)
	}
	onlyInfo := cabinet
	onlyInfo.ResourceTypes = []string{ResourceProductsInfo}
	h, _ := productsHarness(t, o, []string{listPage(1, 5)})
	res := h.exec.Run(context.Background(), task(ResourceProductsInfo, today), onlyInfo, "trace")
	assert.Equal(t, "resource_disabled", res.Outcome, "без списка товаров нет")
	assert.Empty(t, o.productListFrom)
	assert.Empty(t, o.infoCalls)
}

func TestProductInfoStorageAndRawErrors(t *testing.T) {
	o := &fakeOzon{}
	h, reader := productsHarness(t, o, []string{listPage(1, 5)})
	reader.err = errors.New("s3 down")
	res := h.exec.Run(context.Background(), task(ResourceProductsInfo, today), productsCabinet, "trace")
	assert.Equal(t, "store", res.Outcome, "сбой чтения S3 — повтор")

	for name, readErr := range map[string]error{"checksum": rawstore.ErrChecksumMismatch, "missing object": rawstore.ErrObjectNotFound} {
		h2, reader2 := productsHarness(t, o, []string{listPage(1, 5)})
		reader2.err = fmt.Errorf("wrapped: %w", readErr)
		res = h2.exec.Run(context.Background(), task(ResourceProductsInfo, today), productsCabinet, "trace")
		assert.Equal(t, "catalog_raw_invalid", res.Outcome, name)
		assert.Contains(t, h2.logs.String(), `"level":"ERROR"`, name)
	}
	h3, _ := productsHarness(t, o, []string{`{"result":{"items":[{"offer_id":"x"}],"total":1}}`})
	res = h3.exec.Run(context.Background(), task(ResourceProductsInfo, today), productsCabinet, "trace")
	assert.Equal(t, "catalog_raw_invalid", res.Outcome, "товар без product_id")
	assert.Equal(t, store.StatusFailedPermanent, h3.tasks.finished.Status)

	h4, _ := productsHarness(t, o, []string{listPage(1, 5)})
	h4.tasks.contentErr = fmt.Errorf("wrapped: %w", store.ErrMissingObject)
	res = h4.exec.Run(context.Background(), task(ResourceProductsInfo, today), productsCabinet, "trace")
	assert.Equal(t, "catalog_raw_invalid", res.Outcome)
	assert.Empty(t, o.infoCalls)
}

// Попытка, пересёкшая полночь МСК (ожидание лимитера, медленный ответ),
// прерывается на границе суток: страницы нового дня под вчерашней датой не
// сохраняются.
func TestProductSnapshotLoadCrossingMidnightIsMissed(t *testing.T) {
	beforeMidnight := time.Date(2026, 10, 17, 20, 59, 59, 950_000_000, time.UTC) // 23:59:59.95 МСК
	for _, resource := range []string{ResourceProductsList, ResourceProductsInfo} {
		o := &fakeOzon{productHang: true}
		h, _ := productsHarness(t, o, []string{listPage(1, 5)})
		h.exec.now = func() time.Time { return beforeMidnight }

		res := h.exec.Run(context.Background(), task(resource, today), productsCabinet, "trace")

		assert.Equal(t, "snapshot_missed", res.Outcome, resource)
		assert.Equal(t, store.StatusFailedPermanent, h.tasks.finished.Status, resource)
		assert.Empty(t, h.tasks.pages, resource)
		assert.NotContains(t, h.logs.String(), `"level":"ERROR"`, resource)
	}
}

func TestProductSnapshotShutdownIsNotMissed(t *testing.T) {
	o := &fakeOzon{productHang: true}
	h := newHarness(o)
	ctx, cancel := context.WithCancel(context.Background())
	go func() { time.Sleep(20 * time.Millisecond); cancel() }()

	res := h.exec.Run(ctx, task(ResourceProductsList, today), productsCabinet, "trace")

	assert.Equal(t, "released", res.Outcome, "остановка сервиса — не пропуск снимка")
}
