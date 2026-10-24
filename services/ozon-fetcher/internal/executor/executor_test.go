package executor

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"log/slog"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account/accounttest"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/rawstore"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

const secretKey = "seller-secret-key" //nolint:gosec // G101: фиктивный ключ теста

var t0 = time.Date(2026, 10, 17, 9, 0, 0, 0, time.UTC)

// --- фейки ---

type fakeTasks struct {
	mu           sync.Mutex
	pages        []store.RawObject
	cursors      []string
	finished     *store.Outcome
	suspended    []string
	resumed      []string
	stopped      []string
	extends      int
	pageErr      error // ошибка RecordPage
	extendErr    error
	finishErr    error
	revision     int
	known        []store.RawObject // объекты прежних попыток (для FindObject)
	suspendErr   error
	authFailures int             // счётчик отказов кабинета подряд
	pauses       []time.Duration // паузы, выданные RecordAuthFailure
	stopErr      error
	content      map[string][]store.RawObject // ресурс/период → страницы ревизии (ContentPages)
	contentAt    time.Time                    // StartedAt ревизии (по умолчанию t0)
	contentErr   error
	savedCursors []string // SaveCursor
	adsBusy      bool     // AdsReportInFlight
	reloaded     []string
	controlHang  bool // SuspendConnection/StopResourceAllConnections ждут отмены контекста
	extendHang   bool // ExtendLease ждёт отмены контекста
}

func (f *fakeTasks) ExtendLease(ctx context.Context, _ store.TaskKey, _ string, _ time.Time) error {
	f.mu.Lock()
	f.extends++
	hang, err := f.extendHang, f.extendErr
	f.mu.Unlock()
	if hang {
		<-ctx.Done()
		return ctx.Err()
	}
	return err
}

func (f *fakeTasks) RecordPage(_ context.Context, _ store.TaskKey, _ string, obj store.RawObject, next string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.pageErr != nil {
		return f.pageErr
	}
	f.pages = append(f.pages, obj)
	f.cursors = append(f.cursors, next)
	return nil
}

func (f *fakeTasks) FindObject(_ context.Context, _ store.TaskKey, sha string) (store.RawObject, bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	for _, p := range append(append([]store.RawObject(nil), f.known...), f.pages...) {
		if p.SHA256 == sha {
			return p, true, nil
		}
	}
	return store.RawObject{}, false, nil
}

func (f *fakeTasks) FinishTask(_ context.Context, _ store.TaskKey, _ string, out store.Outcome, _ time.Time) (int, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.finished = &out
	return f.revision, f.finishErr
}

func (f *fakeTasks) RecordAuthFailure(ctx context.Context, ref account.Ref, now time.Time, pauseFor func(int) time.Duration) (int, error) {
	if f.controlHang {
		<-ctx.Done()
		return 0, ctx.Err()
	}
	if f.suspendErr != nil {
		return 0, f.suspendErr
	}
	f.authFailures++
	f.suspended = append(f.suspended, accounttest.Label(ref))
	f.pauses = append(f.pauses, pauseFor(f.authFailures))
	return f.authFailures, nil
}

func (f *fakeTasks) ResumeConnection(_ context.Context, ref account.Ref) error {
	f.resumed = append(f.resumed, accounttest.Label(ref))
	return nil
}

func (f *fakeTasks) ContentPages(_ context.Context, key store.TaskKey) (store.Content, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.contentErr != nil {
		return store.Content{}, f.contentErr
	}
	pages, ok := f.content[key.ResourceType+"/"+key.PeriodKey]
	if !ok {
		return store.Content{}, store.ErrNoContent
	}
	at := f.contentAt
	if at.IsZero() {
		at = t0
	}
	return store.Content{Pages: pages, StartedAt: at}, nil
}

func (f *fakeTasks) SaveCursor(_ context.Context, _ store.TaskKey, _, cursor string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.savedCursors = append(f.savedCursors, cursor)
	return nil
}

func (f *fakeTasks) AdsReportInFlight(context.Context, store.TaskKey) (bool, error) {
	return f.adsBusy, nil
}

func (f *fakeTasks) ReloadWithoutContent(_ context.Context, key store.TaskKey, _ time.Time) (bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.reloaded = append(f.reloaded, key.ResourceType+"/"+key.PeriodKey)
	return true, nil
}

func (f *fakeTasks) StopResourceAllConnections(_ context.Context, resource, _ string) error {
	if f.stopErr != nil {
		return f.stopErr
	}
	f.stopped = append(f.stopped, resource)
	return nil
}

type fakeRaw struct {
	mu   sync.Mutex
	puts []rawstore.Object
	fail map[int]error // по номеру страницы
}

func (f *fakeRaw) Put(_ context.Context, obj rawstore.Object) (rawstore.Stored, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if err := f.fail[obj.PageNo]; err != nil {
		return rawstore.Stored{}, err
	}
	f.puts = append(f.puts, obj)
	sum := sha256.Sum256(obj.Body)
	return rawstore.Stored{URI: "s3://b/" + obj.PeriodKey, SHA256: hex.EncodeToString(sum[:]), SizeBytes: int64(len(obj.Body))}, nil
}

// fakeOzon: by-day по курсорам, остальное — заданные ответы.
type fakeOzon struct {
	pages      map[string]ozon.Page // курсор → страница
	byDayErr   error                // после всех страниц
	singlePage ozon.Page
	singleErr  error
	creds      []ozon.Credentials
	block      chan struct{} // FetchAccrualTypes ждёт закрытия или отмены

	postingErr   error
	postingCalls []postingCall

	accrualErr       error
	accrualFailAfter int
	accrualCalls     [][]string

	productPages    map[string]ozon.Page // last_id → страница списка
	productListErr  error                // после всех страниц
	productListFrom []string             // fromLastID каждого вызова
	infoErr         error
	infoFailAfter   int
	infoMissing     int
	infoCalls       [][]int64
	productHang     bool // список и подробности ждут отмены контекста
	// Остатки: страницы по курсору, ошибка при отсутствии страницы, курсоры запросов.
	stockPages map[string]ozon.Page
	stockErr   error
	stockFrom  []string
}

func (f *fakeOzon) FetchAccrualByDay(_ context.Context, _ account.Ref, creds ozon.Credentials, _, cursor string, firstPageNo int, onPage ozon.PageHandler) error {
	f.creds = append(f.creds, creds)
	for no := firstPageNo; ; no++ {
		p, ok := f.pages[cursor]
		if !ok {
			return f.byDayErr
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

func (f *fakeOzon) FetchAccrualTypes(ctx context.Context, _ account.Ref, creds ozon.Credentials) (ozon.Page, error) {
	f.creds = append(f.creds, creds)
	if f.block != nil {
		select {
		case <-f.block:
		case <-ctx.Done():
			return ozon.Page{}, ctx.Err()
		}
	}
	return f.singlePage, f.singleErr
}

func (f *fakeOzon) FetchRealization(_ context.Context, _ account.Ref, _ ozon.Credentials, year, month int) (ozon.Page, error) {
	if year != 2026 || month != 9 {
		return ozon.Page{}, errors.New("unexpected period")
	}
	return f.singlePage, f.singleErr
}

func (f *fakeOzon) FetchMutualSettlement(_ context.Context, _ account.Ref, _ ozon.Credentials, _, _ int, _ ozon.ReportPoll, _ func(context.Context, time.Duration) error) (ozon.Page, error) {
	return f.singlePage, f.singleErr
}

// FetchPostingList — те же страницы по курсорам, что у by-day; ошибка — postingErr.
func (f *fakeOzon) FetchPostingList(_ context.Context, _ account.Ref, creds ozon.Credentials, scheme ozon.PostingScheme, w ozon.PostingWindow, cursor string, firstPageNo int, onPage ozon.PageHandler) error {
	f.creds = append(f.creds, creds)
	f.postingCalls = append(f.postingCalls, postingCall{scheme: scheme, window: w, cursor: cursor, firstPage: firstPageNo})
	if f.postingErr != nil {
		return f.postingErr
	}
	for no := firstPageNo; ; no++ {
		p, ok := f.pages[cursor]
		if !ok {
			return f.byDayErr
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

// FetchAccrualPostings — эхо запрошенных номеров; ошибка — accrualErr, после accrualFailAfter вызовов.
func (f *fakeOzon) FetchAccrualPostings(_ context.Context, _ account.Ref, creds ozon.Credentials, numbers []string) (ozon.Page, error) {
	f.creds = append(f.creds, creds)
	f.accrualCalls = append(f.accrualCalls, append([]string(nil), numbers...))
	if f.accrualErr != nil && len(f.accrualCalls) > f.accrualFailAfter {
		return ozon.Page{}, f.accrualErr
	}
	return ozon.Page{No: 1, Body: []byte(`{"posting_accruals":"` + strings.Join(numbers, ",") + `"}`), ContentType: "application/json"}, nil
}

type postingCall struct {
	scheme    ozon.PostingScheme
	window    ozon.PostingWindow
	cursor    string
	firstPage int
}

type fakeRefresher struct{ calls int }

func (f *fakeRefresher) ForceRefresh(context.Context) (bool, error) {
	f.calls++
	return true, nil
}

func page(body, cursor string) ozon.Page {
	return ozon.Page{Body: []byte(body), Cursor: cursor, ContentType: "application/json"}
}

func twoPageDay() map[string]ozon.Page {
	return map[string]ozon.Page{"": page(`{"p":1}`, "c1"), "c1": page(`{"p":2}`, "")}
}

type harness struct {
	tasks     *fakeTasks
	raw       *fakeRaw
	ozon      *fakeOzon
	refresher *fakeRefresher
	logs      *bytes.Buffer
	exec      *Executor
}

func newHarness(o *fakeOzon) *harness {
	h := &harness{tasks: &fakeTasks{}, raw: &fakeRaw{}, ozon: o, refresher: &fakeRefresher{}, logs: &bytes.Buffer{}}
	cfg := DefaultConfig()
	cfg.Lease = 30 * time.Millisecond
	h.exec = New(cfg, h.tasks, h.raw, h.ozon, h.refresher, slog.New(slog.NewJSONHandler(h.logs, nil)),
		func() time.Time { return t0 }, func(time.Duration) time.Duration { return 0 }, ozon.SleepContext)
	return h
}

func task(resource, period string) store.Task {
	return store.Task{TaskKey: store.TaskKey{Account: accounttest.Ref("ref-a"), ResourceType: resource, PeriodKey: period}, AttemptID: "att-1", Attempts: 1, NextPageNo: 1}
}

var cabinet = connections.Cabinet{Account: accounttest.Ref("ref-a"), Seller: &ozon.Credentials{ClientID: "c", APIKey: secretKey}}

func sha(s string) string {
	sum := sha256.Sum256([]byte(s))
	return hex.EncodeToString(sum[:])
}

// --- тесты ---

func TestByDaySuccessStoresEveryPageBeforeNext(t *testing.T) {
	h := newHarness(&fakeOzon{pages: twoPageDay()})

	res := h.exec.Run(context.Background(), task(ResourceAccrualByDay, "2026-10-15"), cabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	require.Len(t, h.tasks.pages, 2)
	assert.Equal(t, []string{"c1", ""}, h.tasks.cursors)
	assert.Equal(t, []int{1, 2}, []int{h.tasks.pages[0].PageNo, h.tasks.pages[1].PageNo})
	require.NotNil(t, h.tasks.finished)
	assert.Equal(t, store.StatusDone, h.tasks.finished.Status)
	assert.Equal(t, contentHash([]string{sha(`{"p":1}`), sha(`{"p":2}`)}), h.tasks.finished.ContentHash)
	assert.Equal(t, secretKey, h.ozon.creds[0].APIKey)
	assert.NotContains(t, h.logs.String(), secretKey)
	assert.Contains(t, h.logs.String(), `"job_id":"att-1"`)
}

func TestResumedLoadHasSameHashAsLoadAtOnce(t *testing.T) {
	// Попытка 1: страница 1 сохранена, S3 упал на странице 2.
	o := &fakeOzon{pages: twoPageDay()}
	h := newHarness(o)
	h.raw.fail = map[int]error{2: errors.New("s3 down")}
	first := h.exec.Run(context.Background(), task(ResourceAccrualByDay, "2026-10-15"), cabinet, "trace")

	assert.Equal(t, "store", first.Outcome)
	assert.Equal(t, store.StatusFailedRetryable, h.tasks.finished.Status)
	assert.True(t, h.tasks.finished.KeepCursor)
	require.Len(t, h.tasks.pages, 1)

	// Попытка 2 продолжает с сохранённого курсора.
	h2 := newHarness(o)
	resumed := task(ResourceAccrualByDay, "2026-10-15")
	resumed.Cursor, resumed.NextPageNo, resumed.PageHashes = "c1", 2, []string{h.tasks.pages[0].SHA256}
	h2.exec.Run(context.Background(), resumed, cabinet, "trace")

	require.Len(t, h2.tasks.pages, 1, "страница 1 не загружается повторно")
	assert.Equal(t, 2, h2.tasks.pages[0].PageNo)
	assert.Equal(t, contentHash([]string{sha(`{"p":1}`), sha(`{"p":2}`)}), h2.tasks.finished.ContentHash)
}

func TestUnchangedContentIsLabelledUnchanged(t *testing.T) {
	h := newHarness(&fakeOzon{pages: twoPageDay()})
	tk := task(ResourceAccrualByDay, "2026-10-15")
	tk.ContentHash = contentHash([]string{sha(`{"p":1}`), sha(`{"p":2}`)})
	tk.Revision = 1
	h.tasks.revision = 1

	res := h.exec.Run(context.Background(), tk, cabinet, "trace")

	assert.Equal(t, "unchanged", res.Outcome)
	assert.NotContains(t, h.logs.String(), "source revised")
}

func TestRevisedContentIsLogged(t *testing.T) {
	h := newHarness(&fakeOzon{pages: twoPageDay()})
	tk := task(ResourceAccrualByDay, "2026-10-15")
	tk.ContentHash, tk.Revision = sha("old"), 1
	h.tasks.revision = 2

	res := h.exec.Run(context.Background(), tk, cabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, 2, res.Revision)
	assert.Contains(t, h.logs.String(), "source revised")
}

func TestOutcomeTable(t *testing.T) {
	apiErr := func(class ozon.Class, retry time.Duration) error {
		return &ozon.APIError{Class: class, Endpoint: "/v1/x", Status: 400, RetryAfter: retry}
	}
	tests := []struct {
		name         string
		err          error
		lastClass    string
		authFailures int
		attempts     int
		wantStatus   string
		wantLabel    string
		wantKeep     bool
		wantNext     time.Duration
		check        func(t *testing.T, h *harness)
	}{
		{name: "rate limit with retry-after", err: apiErr(ozon.ClassRateLimit, 7*time.Second), wantStatus: store.StatusPending, wantLabel: "rate_limit", wantKeep: true, wantNext: 7 * time.Second},
		{name: "rate limit default pause", err: apiErr(ozon.ClassRateLimit, 0), wantStatus: store.StatusPending, wantLabel: "rate_limit", wantKeep: true, wantNext: 30 * time.Second},
		{name: "transient first", err: apiErr(ozon.ClassTransient, 0), attempts: 1, wantStatus: store.StatusFailedRetryable, wantLabel: "transient", wantKeep: true, wantNext: time.Minute},
		{name: "transient third", err: apiErr(ozon.ClassTransient, 0), attempts: 3, wantStatus: store.StatusFailedRetryable, wantLabel: "transient", wantKeep: true, wantNext: 4 * time.Minute},
		{name: "transient capped", err: apiErr(ozon.ClassTransient, 0), attempts: 30, wantStatus: store.StatusFailedRetryable, wantLabel: "transient", wantKeep: true, wantNext: time.Hour},
		{name: "auth first pauses cabinet briefly", err: apiErr(ozon.ClassAuth, 0), wantStatus: store.StatusPending, wantLabel: "auth", wantKeep: true, check: func(t *testing.T, h *harness) {
			assert.Equal(t, 1, h.refresher.calls)
			assert.Equal(t, []time.Duration{time.Minute}, h.tasks.pauses)
			assert.NotContains(t, h.logs.String(), `"level":"ERROR"`)
		}},
		{name: "auth again on cabinet suspends for long", err: apiErr(ozon.ClassAuth, 0), authFailures: 1, wantStatus: store.StatusPending, wantLabel: "auth", wantKeep: true, check: func(t *testing.T, h *harness) {
			assert.Equal(t, []time.Duration{time.Hour}, h.tasks.pauses, "второй отказ кабинета — длинная пауза, даже если задача другая")
			assert.Contains(t, h.logs.String(), `"level":"ERROR"`)
		}},
		{name: "obsolete stops resource", err: apiErr(ozon.ClassObsoleteEndpoint, 0), wantStatus: store.StatusFailedPermanent, wantLabel: "obsolete_endpoint", check: func(t *testing.T, h *harness) {
			assert.Equal(t, []string{ResourceAccrualTypes}, h.tasks.stopped)
		}},
		{name: "invalid response", err: apiErr(ozon.ClassInvalidResponse, 0), wantStatus: store.StatusFailedPermanent, wantLabel: "invalid_response"},
		{name: "invalid request", err: apiErr(ozon.ClassInvalidRequest, 0), wantStatus: store.StatusFailedPermanent, wantLabel: "invalid_request"},
		{name: "not ready", err: apiErr(ozon.ClassNotReady, 0), wantStatus: store.StatusNotReady, wantLabel: "not_ready", wantNext: time.Hour},
		{name: "unexpected error", err: errors.New("boom"), attempts: 1, wantStatus: store.StatusFailedRetryable, wantLabel: "unexpected", wantKeep: true, wantNext: time.Minute},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			h := newHarness(&fakeOzon{singleErr: tt.err})
			h.tasks.authFailures = tt.authFailures
			tk := task(ResourceAccrualTypes, PeriodRef)
			tk.LastErrorClass = tt.lastClass
			if tt.attempts > 0 {
				tk.Attempts = tt.attempts
			}

			res := h.exec.Run(context.Background(), tk, cabinet, "trace")

			require.NotNil(t, h.tasks.finished)
			out := h.tasks.finished
			assert.Equal(t, tt.wantStatus, out.Status)
			assert.Equal(t, tt.wantLabel, out.Label)
			assert.Equal(t, tt.wantLabel, res.Outcome)
			assert.Equal(t, tt.wantKeep, out.KeepCursor)
			if tt.wantNext > 0 {
				assert.Equal(t, t0.Add(tt.wantNext), out.NextRunAt)
			}
			if tt.check != nil {
				tt.check(t, h)
			}
		})
	}
}

func TestSuccessAfterAuthResumesCabinet(t *testing.T) {
	h := newHarness(&fakeOzon{singlePage: page(`{"accrual_types":[]}`, "")})
	tk := task(ResourceAccrualTypes, PeriodRef)
	tk.LastErrorClass = "auth"

	h.exec.Run(context.Background(), tk, cabinet, "trace")

	assert.Equal(t, []string{"ref-a"}, h.tasks.resumed)
}

func TestMonthlyResources(t *testing.T) {
	for _, resource := range []string{ResourceRealization, ResourceMutualSettlement} {
		t.Run(resource, func(t *testing.T) {
			h := newHarness(&fakeOzon{singlePage: ozon.Page{Body: []byte("report"), ContentType: "application/json"}})
			res := h.exec.Run(context.Background(), task(resource, "2026-09"), cabinet, "trace")
			assert.Equal(t, "done", res.Outcome)
			require.Len(t, h.tasks.pages, 1)
			assert.Equal(t, 1, h.tasks.pages[0].PageNo)
		})
	}

	h := newHarness(&fakeOzon{})
	h.exec.Run(context.Background(), task(ResourceRealization, "2026-9"), cabinet, "trace")
	assert.Equal(t, store.StatusFailedPermanent, h.tasks.finished.Status)
	assert.Equal(t, "invalid_task", h.tasks.finished.Label)
}

func TestNoSellerCredentialsPostpones(t *testing.T) {
	h := newHarness(&fakeOzon{})
	h.exec.Run(context.Background(), task(ResourceAccrualByDay, "2026-10-15"), connections.Cabinet{Account: accounttest.Ref("ref-a"), HasPerformance: true}, "trace")

	assert.Equal(t, "no_credentials", h.tasks.finished.Label)
	assert.Equal(t, t0.Add(time.Hour), h.tasks.finished.NextRunAt)
}

// Обязательный тест services/AGENTS.md §4: задача одного подключения не
// выполняется ключами другого. Реклама — TestAdsTaskIsNotRunWithAnotherConnectionsKey.
func TestTaskIsNotRunWithAnotherConnectionsKeys(t *testing.T) {
	h := newHarness(&fakeOzon{pages: map[string]ozon.Page{"": {Body: []byte(`{}`)}}})
	other := cabinet
	other.Account = accounttest.Ref("ref-b")

	res := h.exec.Run(context.Background(), task(ResourceAccrualByDay, "2026-10-15"), other, "trace")

	assert.Equal(t, "no_credentials", res.Outcome)
	assert.Empty(t, h.ozon.creds, "запросов с ключами другого подключения нет")
	assert.Empty(t, h.raw.puts, "ничего не записано")
}

func TestUnknownResourceIsPermanent(t *testing.T) {
	h := newHarness(&fakeOzon{})
	h.exec.Run(context.Background(), task("ozon.unknown", PeriodRef), cabinet, "trace")
	assert.Equal(t, store.StatusFailedPermanent, h.tasks.finished.Status)
}

func TestShutdownReleasesTaskWithCursor(t *testing.T) {
	o := &fakeOzon{block: make(chan struct{})}
	h := newHarness(o)
	ctx, cancel := context.WithCancel(context.Background())
	time.AfterFunc(10*time.Millisecond, cancel)

	res := h.exec.Run(ctx, task(ResourceAccrualTypes, PeriodRef), cabinet, "trace")

	assert.Equal(t, "released", res.Outcome)
	require.NotNil(t, h.tasks.finished, "итог записан, хотя родительский контекст отменён")
	assert.Equal(t, store.StatusPending, h.tasks.finished.Status)
	assert.True(t, h.tasks.finished.KeepCursor)
}

func TestLeaseIsExtendedDuringLongTask(t *testing.T) {
	o := &fakeOzon{block: make(chan struct{}), singlePage: page(`{}`, "")}
	h := newHarness(o)
	time.AfterFunc(80*time.Millisecond, func() { close(o.block) })

	res := h.exec.Run(context.Background(), task(ResourceAccrualTypes, PeriodRef), cabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	h.tasks.mu.Lock()
	defer h.tasks.mu.Unlock()
	assert.GreaterOrEqual(t, h.tasks.extends, 2)
}

func TestLostLeaseCancelsTaskAndWritesNothing(t *testing.T) {
	o := &fakeOzon{block: make(chan struct{})}
	h := newHarness(o)
	h.tasks.extendErr = store.ErrLeaseLost

	res := h.exec.Run(context.Background(), task(ResourceAccrualTypes, PeriodRef), cabinet, "trace")

	assert.Equal(t, "lease_lost", res.Outcome)
	assert.Nil(t, h.tasks.finished, "результат перехваченной задачи не записывается")
	assert.Contains(t, h.logs.String(), "lease lost")
}

func TestLeaseLostOnRecordPageIsNotFinished(t *testing.T) {
	h := newHarness(&fakeOzon{pages: twoPageDay()})
	h.tasks.pageErr = store.ErrLeaseLost

	res := h.exec.Run(context.Background(), task(ResourceAccrualByDay, "2026-10-15"), cabinet, "trace")

	assert.Equal(t, "lease_lost", res.Outcome)
}

func TestFinishFailureIsLoggedAsError(t *testing.T) {
	h := newHarness(&fakeOzon{singlePage: page(`{}`, "")})
	h.tasks.finishErr = errors.New("db down")

	res := h.exec.Run(context.Background(), task(ResourceAccrualTypes, PeriodRef), cabinet, "trace")

	assert.Equal(t, "finish_failed", res.Outcome)
	assert.True(t, strings.Contains(h.logs.String(), "fetch task finish failed"))
}

func TestDayFullySavedByPreviousAttemptIsNotReloaded(t *testing.T) {
	o := &fakeOzon{pages: twoPageDay()}
	h := newHarness(o)
	tk := task(ResourceAccrualByDay, "2026-10-15")
	tk.Cursor, tk.NextPageNo = "", 3
	tk.PageHashes = []string{sha(`{"p":1}`), sha(`{"p":2}`)}

	res := h.exec.Run(context.Background(), tk, cabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Empty(t, o.creds, "Ozon не вызывался")
	assert.Empty(t, h.tasks.pages)
	assert.Equal(t, contentHash(tk.PageHashes), h.tasks.finished.ContentHash)
}

func TestSinglePageSavedByPreviousAttemptIsNotReloaded(t *testing.T) {
	for _, resource := range []string{ResourceAccrualTypes, ResourceRealization, ResourceMutualSettlement} {
		t.Run(resource, func(t *testing.T) {
			o := &fakeOzon{singlePage: page(`{"x":1}`, "")}
			h := newHarness(o)
			period := PeriodRef
			if resource != ResourceAccrualTypes {
				period = "2026-09"
			}
			tk := task(resource, period)
			tk.PageHashes = []string{sha(`{"x":1}`)}
			tk.NextPageNo = 2

			res := h.exec.Run(context.Background(), tk, cabinet, "trace")

			assert.Equal(t, "done", res.Outcome)
			assert.Empty(t, h.tasks.pages, "страница не загружается и не журналируется повторно")
			assert.Empty(t, h.raw.puts)
			assert.Equal(t, contentHash(tk.PageHashes), h.tasks.finished.ContentHash)
		})
	}
}

func TestHungLeaseRenewalDoesNotBlockFinish(t *testing.T) {
	h := newHarness(&fakeOzon{singlePage: page(`{}`, ""), block: make(chan struct{})})
	h.tasks.extendHang = true
	time.AfterFunc(25*time.Millisecond, func() { close(h.ozon.block) }) // продление (каждые 10 мс) уже висит

	done := make(chan Result, 1)
	go func() {
		done <- h.exec.Run(context.Background(), task(ResourceAccrualTypes, PeriodRef), cabinet, "trace")
	}()

	select {
	case res := <-done:
		assert.Equal(t, "done", res.Outcome)
	case <-time.After(2 * time.Second):
		t.Fatal("Run is stuck behind a hung lease renewal")
	}
}

func TestFailedControlWritesKeepTaskRetryable(t *testing.T) {
	t.Run("auth failure", func(t *testing.T) {
		h := newHarness(&fakeOzon{singleErr: &ozon.APIError{Class: ozon.ClassAuth, Status: 401}})
		h.tasks.suspendErr = errors.New("db down")

		h.exec.Run(context.Background(), task(ResourceAccrualTypes, PeriodRef), cabinet, "trace")

		assert.Equal(t, store.StatusFailedRetryable, h.tasks.finished.Status)
		assert.Equal(t, t0.Add(time.Minute), h.tasks.finished.NextRunAt, "не сразу — backoff")
		assert.Zero(t, h.refresher.calls)
	})
	t.Run("stop resource", func(t *testing.T) {
		h := newHarness(&fakeOzon{singleErr: &ozon.APIError{Class: ozon.ClassObsoleteEndpoint, Status: 400}})
		h.tasks.stopErr = errors.New("db down")

		h.exec.Run(context.Background(), task(ResourceAccrualTypes, PeriodRef), cabinet, "trace")

		assert.Equal(t, store.StatusFailedRetryable, h.tasks.finished.Status)
		assert.NotContains(t, h.logs.String(), "resource stopped")
	})
}

func TestHungControlWriteIsBoundedAndKeepsTaskRetryable(t *testing.T) {
	h := newHarness(&fakeOzon{singleErr: &ozon.APIError{Class: ozon.ClassAuth, Status: 401}})
	h.exec.cfg.FinishTimeout = 30 * time.Millisecond
	h.tasks.controlHang = true
	tk := task(ResourceAccrualTypes, PeriodRef)
	tk.LastErrorClass = "auth"
	ctx, cancel := context.WithCancel(context.Background())
	cancel() // shutdown уже идёт

	done := make(chan Result, 1)
	go func() { done <- h.exec.Run(ctx, tk, cabinet, "trace") }()

	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("Run is stuck behind a hung control write")
	}
	require.NotNil(t, h.tasks.finished)
}

func TestUnchangedPagesAreNotUploadedAgain(t *testing.T) {
	h := newHarness(&fakeOzon{pages: twoPageDay()})
	h.tasks.known = []store.RawObject{{PageNo: 1, StorageURI: "s3://b/old-p1", SHA256: sha(`{"p":1}`), StoredBytes: 7}}

	res := h.exec.Run(context.Background(), task(ResourceAccrualByDay, "2026-10-15"), cabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	require.Len(t, h.raw.puts, 1, "в S3 — только изменившаяся страница")
	assert.Equal(t, 2, h.raw.puts[0].PageNo)
	require.Len(t, h.tasks.pages, 2, "журнал попытки — обе страницы")
	assert.Equal(t, "s3://b/old-p1", h.tasks.pages[0].StorageURI)
	assert.Contains(t, h.logs.String(), `"pages_reused":1`)
}

func TestAnySuccessResetsCabinetAuthCounter(t *testing.T) {
	h := newHarness(&fakeOzon{singlePage: page(`{"accrual_types":[]}`, "")})

	h.exec.Run(context.Background(), task(ResourceAccrualTypes, PeriodRef), cabinet, "trace") // задача сама 401 не получала

	assert.Equal(t, []string{"ref-a"}, h.tasks.resumed, "успех кабинета сбрасывает счётчик отказов ключа")
}
