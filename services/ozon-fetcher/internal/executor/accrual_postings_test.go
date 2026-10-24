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

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/rawstore"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

// fakeReader — сохранённые страницы по URI.
type fakeReader struct {
	bodies map[string]string
	err    error
	reads  []string
}

func (f *fakeReader) Get(_ context.Context, uri, _ string) ([]byte, error) {
	f.reads = append(f.reads, uri)
	if f.err != nil {
		return nil, f.err
	}
	b, ok := f.bodies[uri]
	if !ok {
		return nil, fmt.Errorf("no object %s", uri)
	}
	return []byte(b), nil
}

var accrualCabinet = func() connections.Cabinet {
	c := cabinet
	c.ResourceTypes = []string{ResourceOrdersFBO, ResourceOrdersFBS, ResourceAccrualPostings}
	return c
}()

func ordersPage(numbers ...string) string {
	items := make([]string, len(numbers))
	for i, n := range numbers {
		items[i] = fmt.Sprintf(`{"posting_number":%q,"status":"delivered"}`, n)
	}
	return `{"postings":[` + strings.Join(items, ",") + `],"has_next":false,"cursor":""}`
}

// accrualHarness — заказы дня: FBO — страницы fbo, FBS — страницы fbs.
func accrualHarness(t *testing.T, o *fakeOzon, fbo, fbs []string) (*harness, *fakeReader) {
	t.Helper()
	h := newHarness(o)
	reader := &fakeReader{bodies: map[string]string{}}
	h.tasks.content = map[string][]store.RawObject{}
	for resource, pages := range map[string][]string{ResourceOrdersFBO: fbo, ResourceOrdersFBS: fbs} {
		if pages == nil {
			continue
		}
		objs := []store.RawObject{}
		for i, body := range pages {
			uri := fmt.Sprintf("s3://raw/%s/p%d", resource, i+1)
			reader.bodies[uri] = body
			objs = append(objs, store.RawObject{PageNo: i + 1, StorageURI: uri, SHA256: sha(body)})
		}
		h.tasks.content[resource+"/2026-10-19"] = objs
	}
	// Ревизия заказов записана после конца дня (ночное дочитывание).
	h.tasks.contentAt = time.Date(2026, 10, 19, 21, 30, 0, 0, time.UTC)
	h.exec = h.exec.WithRawReader(reader)
	return h, reader
}

func TestAccrualPostingsUseOrderNumbersOfDaySortedDedupedInChunks(t *testing.T) {
	var fbo []string
	var numbers []string
	for i := range 250 {
		numbers = append(numbers, fmt.Sprintf("FBO-%03d", i))
	}
	fbo = append(fbo, ordersPage(numbers[:150]...), ordersPage(numbers[150:]...))
	fbs := []string{ordersPage("FBS-1", "FBO-001")} // дубль номера FBO
	o := &fakeOzon{}
	h, _ := accrualHarness(t, o, fbo, fbs)

	res := h.exec.Run(context.Background(), task(ResourceAccrualPostings, "2026-10-19"), accrualCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	require.Len(t, o.accrualCalls, 2)
	assert.Len(t, o.accrualCalls[0], 200)
	assert.Len(t, o.accrualCalls[1], 51, "250 FBO + 1 FBS, дубль один раз")
	assert.Equal(t, "FBO-000", o.accrualCalls[0][0])
	assert.Equal(t, "FBS-1", o.accrualCalls[1][50], "по возрастанию")
	fp := numbersFingerprint(append(append([]string(nil), o.accrualCalls[0]...), o.accrualCalls[1]...))
	assert.Equal(t, []string{fp + ":" + o.accrualCalls[0][199], ""}, h.tasks.cursors, "курсор — отпечаток списка и последний номер порции")
	assert.Equal(t, []int{1, 2}, []int{h.tasks.pages[0].PageNo, h.tasks.pages[1].PageNo})
	assert.Equal(t, "ozon.finance.accrual-postings", h.raw.puts[0].ResourceType)
}

func TestAccrualPostingsResumeAfterSavedChunk(t *testing.T) {
	var numbers []string
	for i := range 450 {
		numbers = append(numbers, fmt.Sprintf("N-%03d", i))
	}
	o := &fakeOzon{}
	h, _ := accrualHarness(t, o, []string{ordersPage(numbers...)}, []string{ordersPage()})
	tk := task(ResourceAccrualPostings, "2026-10-19")
	tk.Cursor, tk.NextPageNo, tk.PageHashes = numbersFingerprint(numbers)+":N-199", 2, []string{"h1"}

	res := h.exec.Run(context.Background(), tk, accrualCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	require.Len(t, o.accrualCalls, 2, "первая порция не перезапрашивается")
	assert.Equal(t, "N-200", o.accrualCalls[0][0])
	assert.Equal(t, "N-449", o.accrualCalls[1][len(o.accrualCalls[1])-1])
	assert.Equal(t, []int{2, 3}, []int{h.tasks.pages[0].PageNo, h.tasks.pages[1].PageNo})
}

func TestAccrualPostingsFailureKeepsSavedChunkCursor(t *testing.T) {
	var numbers []string
	for i := range 300 {
		numbers = append(numbers, fmt.Sprintf("N-%03d", i))
	}
	o := &fakeOzon{accrualErr: &ozon.APIError{Class: ozon.ClassRateLimit, Endpoint: ozon.EndpointAccrualPostings, Status: 429}, accrualFailAfter: 1}
	h, _ := accrualHarness(t, o, []string{ordersPage(numbers...)}, []string{ordersPage()})

	res := h.exec.Run(context.Background(), task(ResourceAccrualPostings, "2026-10-19"), accrualCabinet, "trace")

	assert.Equal(t, "rate_limit", res.Outcome)
	assert.Equal(t, []string{numbersFingerprint(numbers) + ":N-199"}, h.tasks.cursors)
	assert.True(t, h.tasks.finished.KeepCursor)
}

func TestAccrualPostingsWaitForOrdersOfTheDay(t *testing.T) {
	o := &fakeOzon{}
	h, _ := accrualHarness(t, o, []string{ordersPage("A")}, nil) // FBS дня ещё не загружен

	res := h.exec.Run(context.Background(), task(ResourceAccrualPostings, "2026-10-19"), accrualCabinet, "trace")

	assert.Equal(t, "orders_not_loaded", res.Outcome)
	assert.Equal(t, store.StatusNotReady, h.tasks.finished.Status)
	assert.Equal(t, t0.Add(DefaultConfig().NotReadyDelay[ResourceAccrualPostings]), h.tasks.finished.NextRunAt)
	assert.Empty(t, o.accrualCalls)
}

func TestAccrualPostingsReadOnlyOrdersResourcesOfCabinet(t *testing.T) {
	o := &fakeOzon{}
	h, _ := accrualHarness(t, o, []string{ordersPage("A")}, nil)
	onlyFBO := cabinet
	onlyFBO.ResourceTypes = []string{ResourceOrdersFBO, ResourceAccrualPostings}

	res := h.exec.Run(context.Background(), task(ResourceAccrualPostings, "2026-10-19"), onlyFBO, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, [][]string{{"A"}}, o.accrualCalls)
}

func TestAccrualPostingsEmptyDayStoresMarkerWithoutRequest(t *testing.T) {
	o := &fakeOzon{}
	h, _ := accrualHarness(t, o, []string{ordersPage()}, []string{ordersPage()})

	res := h.exec.Run(context.Background(), task(ResourceAccrualPostings, "2026-10-19"), accrualCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Empty(t, o.accrualCalls)
	require.Len(t, h.raw.puts, 1)
	assert.JSONEq(t, `{"posting_accruals":[]}`, string(h.raw.puts[0].Body))
}

func TestAccrualPostingsDisabledOrWithoutOrdersDoNotCallOzon(t *testing.T) {
	o := &fakeOzon{}
	h, _ := accrualHarness(t, o, []string{ordersPage("A")}, nil)
	res := h.exec.Run(context.Background(), task(ResourceAccrualPostings, "2026-10-19"), ordersCabinet, "trace")
	assert.Equal(t, "resource_disabled", res.Outcome, "ресурс не выдан кабинету")

	noOrders := cabinet
	noOrders.ResourceTypes = []string{ResourceAccrualPostings}
	res = h.exec.Run(context.Background(), task(ResourceAccrualPostings, "2026-10-19"), noOrders, "trace")
	assert.Equal(t, "resource_disabled", res.Outcome, "без заказов номеров нет")
	assert.Empty(t, o.accrualCalls)
}

func TestAccrualPostingsStorageAndRawErrors(t *testing.T) {
	o := &fakeOzon{}
	h, reader := accrualHarness(t, o, []string{ordersPage("A")}, []string{ordersPage("B")})
	reader.err = errors.New("s3 down")
	res := h.exec.Run(context.Background(), task(ResourceAccrualPostings, "2026-10-19"), accrualCabinet, "trace")
	assert.Equal(t, "store", res.Outcome, "сбой чтения S3 — повтор")
	assert.Equal(t, store.StatusFailedRetryable, h.tasks.finished.Status)

	for name, readErr := range map[string]error{"checksum": rawstore.ErrChecksumMismatch, "missing object": rawstore.ErrObjectNotFound} {
		h3, reader3 := accrualHarness(t, o, []string{ordersPage("A")}, []string{ordersPage("B")})
		reader3.err = fmt.Errorf("wrapped: %w", readErr)
		res = h3.exec.Run(context.Background(), task(ResourceAccrualPostings, "2026-10-19"), accrualCabinet, "trace")
		assert.Equal(t, "orders_raw_invalid", res.Outcome, name)
		assert.Contains(t, h3.logs.String(), `"level":"ERROR"`, name)
	}
	h4, _ := accrualHarness(t, o, []string{ordersPage("A")}, []string{ordersPage("B")})
	h4.tasks.contentErr = fmt.Errorf("wrapped: %w", store.ErrMissingObject)
	res = h4.exec.Run(context.Background(), task(ResourceAccrualPostings, "2026-10-19"), accrualCabinet, "trace")
	assert.Equal(t, "orders_raw_invalid", res.Outcome, "страница ревизии без объекта")

	h2, _ := accrualHarness(t, o, []string{`{"not":"orders"}`}, []string{ordersPage("B")})
	res = h2.exec.Run(context.Background(), task(ResourceAccrualPostings, "2026-10-19"), accrualCabinet, "trace")
	assert.Equal(t, "orders_raw_invalid", res.Outcome)
	assert.Equal(t, store.StatusFailedPermanent, h2.tasks.finished.Status)
	assert.Empty(t, o.accrualCalls)
}

func TestAccrualPostingsAllChunksSavedIsNotRefetched(t *testing.T) {
	o := &fakeOzon{}
	h, reader := accrualHarness(t, o, []string{ordersPage("A")}, []string{ordersPage("B")})
	tk := task(ResourceAccrualPostings, "2026-10-19")
	tk.PageHashes = []string{"h1"}

	res := h.exec.Run(context.Background(), tk, accrualCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Empty(t, o.accrualCalls)
	assert.Empty(t, reader.reads)
}

func TestAccrualPostingsRestartWhenOrdersChangedSinceSavedChunk(t *testing.T) {
	var numbers []string
	for i := range 300 {
		numbers = append(numbers, fmt.Sprintf("N-%03d", i))
	}
	o := &fakeOzon{}
	// Список дня пополнился номером меньше курсора.
	h, _ := accrualHarness(t, o, []string{ordersPage(append(numbers, "N-050a")...)}, []string{ordersPage()})
	tk := task(ResourceAccrualPostings, "2026-10-19")
	tk.Cursor, tk.NextPageNo, tk.PageHashes = numbersFingerprint(numbers)+":N-199", 2, []string{"h1"}

	res := h.exec.Run(context.Background(), tk, accrualCabinet, "trace")

	assert.Equal(t, "orders_revised", res.Outcome)
	assert.Empty(t, o.accrualCalls, "продолжения по чужому списку нет")
	assert.Equal(t, store.StatusFailedRetryable, h.tasks.finished.Status)
	assert.False(t, h.tasks.finished.KeepCursor, "день — заново с первой порции")
}

func TestAccrualPostingsWaitForOrdersReloadedAfterTheDay(t *testing.T) {
	o := &fakeOzon{}
	h, _ := accrualHarness(t, o, []string{ordersPage("A")}, []string{ordersPage()})
	// Последняя загрузка заказов — ещё «сегодня» (до полуночи МСК).
	h.tasks.contentAt = time.Date(2026, 10, 19, 20, 50, 0, 0, time.UTC)

	res := h.exec.Run(context.Background(), task(ResourceAccrualPostings, "2026-10-19"), accrualCabinet, "trace")

	assert.Equal(t, "orders_not_loaded", res.Outcome)
	assert.Empty(t, o.accrualCalls)
}

func TestAccrualPostingsRequeueOrdersLoadedBeforeContentPages(t *testing.T) {
	o := &fakeOzon{}
	h, _ := accrualHarness(t, o, nil, []string{ordersPage()}) // FBO без списка страниц ревизии

	res := h.exec.Run(context.Background(), task(ResourceAccrualPostings, "2026-10-19"), accrualCabinet, "trace")

	assert.Equal(t, "orders_not_loaded", res.Outcome)
	assert.Equal(t, []string{ResourceOrdersFBO + "/2026-10-19"}, h.tasks.reloaded)
}
