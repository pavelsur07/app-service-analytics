package executor

import (
	"context"
	"errors"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

// ordersCabinet — кабинет с включёнными отправлениями.
var ordersCabinet = func() connections.Cabinet {
	c := cabinet
	c.ResourceTypes = []string{ResourceOrdersFBO, ResourceOrdersFBS}
	return c
}()

func TestPostingResourceDisabledForCabinetIsNotFetched(t *testing.T) {
	h := newHarness(&fakeOzon{pages: twoPageDay()})
	tk := task(ResourceOrdersFBO, "2026-10-19")
	tk.Cursor, tk.NextPageNo = "c1", 2

	res := h.exec.Run(context.Background(), tk, cabinet, "trace")

	assert.Equal(t, "resource_disabled", res.Outcome)
	assert.Empty(t, h.ozon.postingCalls, "снятый ресурс не ходит в Ozon")
	assert.Equal(t, store.StatusPending, h.tasks.finished.Status)
	assert.True(t, h.tasks.finished.KeepCursor)
	assert.Equal(t, t0.Add(DefaultConfig().NoCredentialsIn), h.tasks.finished.NextRunAt)
}

func TestPostingResourcesMapToScheme(t *testing.T) {
	for resource, want := range map[string]ozon.PostingScheme{ResourceOrdersFBO: ozon.SchemeFBO, ResourceOrdersFBS: ozon.SchemeFBS} {
		t.Run(resource, func(t *testing.T) {
			h := newHarness(&fakeOzon{pages: twoPageDay()})

			res := h.exec.Run(context.Background(), task(resource, "2026-10-19"), ordersCabinet, "trace")

			assert.Equal(t, "done", res.Outcome)
			require.Len(t, h.ozon.postingCalls, 1)
			assert.Equal(t, want, h.ozon.postingCalls[0].scheme)
			assert.True(t, IsPostingResource(resource))
		})
	}
	assert.False(t, IsPostingResource(ResourceAccrualByDay))
}

func TestPostingWindowIsMoscowDay(t *testing.T) {
	h := newHarness(&fakeOzon{pages: twoPageDay()})

	h.exec.Run(context.Background(), task(ResourceOrdersFBO, "2026-10-19"), ordersCabinet, "trace")

	require.Len(t, h.ozon.postingCalls, 1)
	w := h.ozon.postingCalls[0].window
	assert.Equal(t, time.Date(2026, 10, 18, 21, 0, 0, 0, time.UTC), w.From.UTC())
	assert.Equal(t, time.Date(2026, 10, 19, 21, 0, 0, 0, time.UTC), w.To.UTC())
}

func TestPostingPagesStoredWithCursorAndRawKey(t *testing.T) {
	h := newHarness(&fakeOzon{pages: twoPageDay()})

	h.exec.Run(context.Background(), task(ResourceOrdersFBS, "2026-10-19"), ordersCabinet, "trace")

	require.Len(t, h.tasks.pages, 2)
	assert.Equal(t, []string{"c1", ""}, h.tasks.cursors)
	require.Len(t, h.raw.puts, 2)
	assert.Equal(t, ResourceOrdersFBS, h.raw.puts[0].ResourceType)
	assert.Equal(t, "2026-10-19", h.raw.puts[0].PeriodKey)
	assert.Equal(t, "ozon", h.raw.puts[0].Provider)
	assert.Equal(t, store.StatusDone, h.tasks.finished.Status)
}

func TestPostingResumeContinuesFromSavedPage(t *testing.T) {
	o := &fakeOzon{pages: twoPageDay()}
	h := newHarness(o)
	h.raw.fail = map[int]error{2: errors.New("s3 down")}
	first := h.exec.Run(context.Background(), task(ResourceOrdersFBO, "2026-10-19"), ordersCabinet, "trace")
	require.Equal(t, "store", first.Outcome)
	require.True(t, h.tasks.finished.KeepCursor)

	h2 := newHarness(o)
	resumed := task(ResourceOrdersFBO, "2026-10-19")
	resumed.Cursor, resumed.NextPageNo, resumed.PageHashes = "c1", 2, []string{h.tasks.pages[0].SHA256}
	h2.exec.Run(context.Background(), resumed, ordersCabinet, "trace")

	last := o.postingCalls[len(o.postingCalls)-1]
	assert.Equal(t, "c1", last.cursor)
	assert.Equal(t, 2, last.firstPage)
	require.Len(t, h2.tasks.pages, 1, "страница 1 не загружается повторно")
	assert.Equal(t, contentHash([]string{sha(`{"p":1}`), sha(`{"p":2}`)}), h2.tasks.finished.ContentHash)
}

func TestPostingAllPagesAlreadySavedIsNotRefetched(t *testing.T) {
	o := &fakeOzon{pages: twoPageDay()}
	h := newHarness(o)
	tk := task(ResourceOrdersFBO, "2026-10-19")
	tk.PageHashes = []string{sha(`{"p":1}`), sha(`{"p":2}`)}

	res := h.exec.Run(context.Background(), tk, ordersCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Empty(t, o.postingCalls)
}

func TestPostingUnchangedAndRevised(t *testing.T) {
	same := task(ResourceOrdersFBO, "2026-10-19")
	same.ContentHash = contentHash([]string{sha(`{"p":1}`), sha(`{"p":2}`)})
	h := newHarness(&fakeOzon{pages: twoPageDay()})
	assert.Equal(t, "unchanged", h.exec.Run(context.Background(), same, ordersCabinet, "trace").Outcome)

	changed := task(ResourceOrdersFBO, "2026-10-19")
	changed.ContentHash, changed.Revision = sha("old"), 1
	h2 := newHarness(&fakeOzon{pages: twoPageDay()})
	h2.tasks.revision = 2
	res := h2.exec.Run(context.Background(), changed, ordersCabinet, "trace")
	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, 2, res.Revision)
}

func TestPostingObsoleteStopsOnlyThatResource(t *testing.T) {
	h := newHarness(&fakeOzon{postingErr: &ozon.APIError{Class: ozon.ClassObsoleteEndpoint, Endpoint: ozon.EndpointPostingFBOList, Status: 400, Code: 9, Message: "obsolete method cannot be used"}})

	res := h.exec.Run(context.Background(), task(ResourceOrdersFBO, "2026-10-19"), ordersCabinet, "trace")

	assert.Equal(t, "obsolete_endpoint", res.Outcome)
	assert.Equal(t, []string{ResourceOrdersFBO}, h.tasks.stopped)
	assert.Equal(t, store.StatusFailedPermanent, h.tasks.finished.Status)
	assert.Contains(t, h.logs.String(), "obsolete method cannot be used", "текст ошибки Ozon — в логе задачи")
}

func TestPostingInvalidRequestIsPermanentWithoutStop(t *testing.T) {
	h := newHarness(&fakeOzon{postingErr: &ozon.APIError{Class: ozon.ClassInvalidRequest, Endpoint: ozon.EndpointPostingFBSList, Status: 400, Code: 3, Message: "filter.since and filter.to must not be empty"}})

	res := h.exec.Run(context.Background(), task(ResourceOrdersFBS, "2026-10-19"), ordersCabinet, "trace")

	assert.Equal(t, "invalid_request", res.Outcome)
	assert.Equal(t, store.StatusFailedPermanent, h.tasks.finished.Status)
	assert.Empty(t, h.tasks.stopped, "метод не снят — ресурс не останавливается для всех")
}

func TestPostingBadPeriodIsInvalidTask(t *testing.T) {
	h := newHarness(&fakeOzon{pages: twoPageDay()})

	res := h.exec.Run(context.Background(), task(ResourceOrdersFBO, "2026-10"), ordersCabinet, "trace")

	assert.Equal(t, "invalid_task", res.Outcome)
	assert.Empty(t, h.ozon.postingCalls)
}

func TestPostingRejectedResumedCursorRestartsWindow(t *testing.T) {
	h := newHarness(&fakeOzon{postingErr: &ozon.APIError{Class: ozon.ClassInvalidRequest, Endpoint: ozon.EndpointPostingFBOList, Status: 400, Code: 3, Message: "invalid cursor"}})
	tk := task(ResourceOrdersFBO, "2026-10-19")
	tk.Cursor, tk.NextPageNo, tk.PageHashes = "c-old", 4, []string{"h1", "h2", "h3"}

	res := h.exec.Run(context.Background(), tk, ordersCabinet, "trace")

	assert.Equal(t, "stale_cursor", res.Outcome)
	assert.Equal(t, store.StatusFailedRetryable, h.tasks.finished.Status)
	assert.False(t, h.tasks.finished.KeepCursor, "повтор — с первой страницы окна")
}
