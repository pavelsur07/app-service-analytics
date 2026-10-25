package executor

import (
	"context"
	"fmt"
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
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozonperf"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

// reportFake — заказ, статус и скачивание отчётов.
type reportFake struct {
	statusErr error
	orderHang bool // заказ ждёт отмены контекста (лимитер, медленный ответ)
	orders    [][]string
	ranges    [][2]string
	orderErr  error
	states    []ozonperf.ReportState // последовательность статусов; пусто — сразу готов
	polled    []string
	downloads []string
}

func (f *fakePerf) OrderReport(ctx context.Context, _ account.Ref, _ ozonperf.Credentials, campaigns []string, from, to time.Time) (string, error) {
	r := f.reports
	if r.orderHang {
		<-ctx.Done()
		return "", ctx.Err()
	}
	if r.orderErr != nil {
		return "", r.orderErr
	}
	r.orders = append(r.orders, append([]string(nil), campaigns...))
	r.ranges = append(r.ranges, [2]string{from.Format(time.DateOnly), to.Format(time.DateOnly)})
	return fmt.Sprintf("uuid-%d", len(r.orders)), nil
}

func (f *fakePerf) ReportStatus(_ context.Context, _ account.Ref, _ ozonperf.Credentials, id string) (ozonperf.ReportStatus, error) {
	r := f.reports
	r.polled = append(r.polled, id)
	if r.statusErr != nil {
		return ozonperf.ReportStatus{}, r.statusErr
	}
	if len(r.states) == 0 {
		return ozonperf.ReportStatus{State: ozonperf.ReportReady, Raw: "OK", Link: "/api/client/statistics/report?UUID=" + id}, nil
	}
	st := r.states[0]
	r.states = r.states[1:]
	return ozonperf.ReportStatus{State: st, Raw: string(st)}, nil
}

func (f *fakePerf) DownloadReport(_ context.Context, _ account.Ref, _ ozonperf.Credentials, id, _ string) (ozon.Page, error) {
	f.reports.downloads = append(f.reports.downloads, id)
	return ozon.Page{No: 1, Body: []byte("PK\x03\x04" + id), ContentType: "application/zip"}, nil
}

type fakeClock struct {
	mu  sync.Mutex
	now time.Time
}

func (c *fakeClock) Now() time.Time { c.mu.Lock(); defer c.mu.Unlock(); return c.now }
func (c *fakeClock) Sleep(ctx context.Context, d time.Duration) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	c.mu.Lock()
	c.now = c.now.Add(d)
	c.mu.Unlock()
	return nil
}

var reportsCabinet = connections.Cabinet{Account: accounttest.Ref("ref-a"),
	Performance:   &ozonperf.Credentials{ClientID: "perf-1", ClientSecret: perfSecret},
	ResourceTypes: []string{ResourceAdsCampaigns, ResourceAdsDaily, ResourceAdsStatistics, ResourceAdsStatisticsTail}}

func campaignsBody(ids ...int) string {
	items := []string{`{"id":"999","advObjectType":"SEARCH_PROMO","state":"CAMPAIGN_STATE_RUNNING"}`}
	for _, id := range ids {
		items = append(items, fmt.Sprintf(`{"id":"%d","advObjectType":"SKU","state":"CAMPAIGN_STATE_RUNNING","title":"T"}`, id))
	}
	return `{"list":[` + strings.Join(items, ",") + `],"total":"1"}`
}

// reportsHarness — сейчас at (МСК), кампании дня исполнения, дневная
// статистика по дням (nil — нет).
func reportsHarness(t *testing.T, at string, campaigns string, daily map[string]string) (*harness, *fakePerf, *fakeClock) {
	t.Helper()
	p := &fakePerf{reports: &reportFake{}}
	h := adsHarness(p)
	clk := &fakeClock{now: msk(t, at)}
	h.exec.now, h.exec.sleep = clk.Now, clk.Sleep
	reader := &fakeReader{bodies: map[string]string{}}
	h.tasks.content = map[string][]store.RawObject{}
	if campaigns != "" {
		uri := "s3://raw/campaigns"
		reader.bodies[sha(campaigns)] = campaigns
		h.tasks.content[ResourceAdsCampaigns+"/"+clk.Now().In(DefaultConfig().Location).Format(time.DateOnly)] = []store.RawObject{{PageNo: 1, StorageURI: uri, SHA256: sha(campaigns)}}
	}
	for day, body := range daily {
		uri := "s3://raw/daily/" + day
		reader.bodies[sha(body)] = body
		h.tasks.content[ResourceAdsDaily+"/"+day] = []store.RawObject{{PageNo: 1, StorageURI: uri, SHA256: sha(body)}}
	}
	h.exec = h.exec.WithRawReader(reader)
	return h, p, clk
}

func reportTask(resource, period string) store.Task { return task(resource, period) }

func TestAdsReportOrdersBatchesOfTenAndStoresEachReport(t *testing.T) {
	ids := []int{12, 3, 1, 2, 4, 5, 6, 7, 8, 9, 10, 11}
	h, p, _ := reportsHarness(t, "2026-10-18 01:30", campaignsBody(ids...), nil)

	res := h.exec.Run(context.Background(), reportTask(ResourceAdsStatistics, "2026-10-17"), reportsCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	require.Len(t, p.reports.orders, 2)
	assert.Equal(t, []string{"1", "2", "3", "4", "5", "6", "7", "8", "9", "10"}, p.reports.orders[0], "только SKU, по возрастанию id")
	assert.Equal(t, []string{"11", "12"}, p.reports.orders[1])
	assert.Equal(t, [2]string{"2026-10-17", "2026-10-17"}, p.reports.ranges[0])
	assert.Equal(t, []string{"uuid-1", "uuid-2"}, p.reports.downloads)
	require.Len(t, h.tasks.pages, 2)
	assert.Equal(t, "application/zip", h.raw.puts[0].ContentType)
	assert.NotEmpty(t, h.tasks.cursors[0], "после первой порции — курсор следующей")
	assert.Empty(t, h.tasks.cursors[1])
	require.Len(t, h.tasks.savedCursors, 4, "резерв до заказа, UUID — сразу после")
	assert.Contains(t, h.tasks.savedCursors[0], ":"+reportReserved+":")
	assert.Contains(t, h.tasks.savedCursors[1], ":uuid-1:")
	assert.NotContains(t, h.logs.String(), perfSecret)
}

func TestAdsReportOnlyActiveCampaignsByDailyStatistics(t *testing.T) {
	daily := map[string]string{"2026-10-17": `{"rows":[{"id":"3","views":"10","clicks":"0","moneySpent":"0"},{"id":"4","views":"0","clicks":"0","moneySpent":"0,00"},{"id":"5","views":"0","clicks":"0","moneySpent":"12,5"}]}`}
	h, p, _ := reportsHarness(t, "2026-10-18 01:30", campaignsBody(3, 4, 5, 6), daily)

	res := h.exec.Run(context.Background(), reportTask(ResourceAdsStatistics, "2026-10-17"), reportsCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	require.Len(t, p.reports.orders, 1)
	assert.Equal(t, []string{"3", "5"}, p.reports.orders[0], "без показов и расхода — без отчёта")
}

func TestAdsReportUnknownDailyShapeTakesAllSKU(t *testing.T) {
	daily := map[string]string{"2026-10-17": `{"something":"else"}`}
	h, p, _ := reportsHarness(t, "2026-10-18 01:30", campaignsBody(3, 4), daily)

	res := h.exec.Run(context.Background(), reportTask(ResourceAdsStatistics, "2026-10-17"), reportsCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, [][]string{{"3", "4"}}, p.reports.orders)
}

func TestAdsReportNoActiveCampaignsStoresMarkerWithoutOrder(t *testing.T) {
	daily := map[string]string{"2026-10-17": `{"rows":[]}`}
	h, p, _ := reportsHarness(t, "2026-10-18 01:30", campaignsBody(3), daily)

	res := h.exec.Run(context.Background(), reportTask(ResourceAdsStatistics, "2026-10-17"), reportsCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Empty(t, p.reports.orders)
	require.Len(t, h.raw.puts, 1)
	assert.JSONEq(t, emptyAdsReportPage, string(h.raw.puts[0].Body))
}

func TestAdsReportTailRangeIsFourteenDaysBeforeRunDay(t *testing.T) {
	h, p, _ := reportsHarness(t, "2026-10-22 01:30", campaignsBody(3), nil)

	res := h.exec.Run(context.Background(), reportTask(ResourceAdsStatisticsTail, "2026-10-22"), reportsCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, [2]string{"2026-10-08", "2026-10-21"}, p.reports.ranges[0])
}

func TestAdsReportOutsideWindowWaitsWithoutOrder(t *testing.T) {
	for at, wait := range map[string]string{"2026-10-18 00:10": "2026-10-18 00:30", "2026-10-18 04:00": "2026-10-19 00:30", "2026-10-18 12:00": "2026-10-19 00:30"} {
		h, p, _ := reportsHarness(t, at, campaignsBody(3), nil)

		res := h.exec.Run(context.Background(), reportTask(ResourceAdsStatistics, "2026-10-17"), reportsCabinet, "trace")

		assert.Equal(t, labelReportWait, res.Outcome, at)
		assert.Equal(t, store.StatusPending, h.tasks.finished.Status, at)
		assert.True(t, h.tasks.finished.KeepCursor, at)
		assert.True(t, h.tasks.finished.NextRunAt.Equal(msk(t, wait)), "%s → %s, got %s", at, wait, h.tasks.finished.NextRunAt)
		assert.Empty(t, p.reports.orders, at)
		assert.NotContains(t, h.logs.String(), `"level":"WARN"`, at)
	}
}

func TestAdsReportSecondReportOfAccountIsNotOrdered(t *testing.T) {
	h, p, _ := reportsHarness(t, "2026-10-18 01:30", campaignsBody(3), nil)
	h.tasks.adsBusy = true

	res := h.exec.Run(context.Background(), reportTask(ResourceAdsStatistics, "2026-10-17"), reportsCabinet, "trace")

	assert.Equal(t, labelReportBusy, res.Outcome)
	assert.Empty(t, p.reports.orders)
}

func TestAdsReportNotReadyKeepsUUIDAndResumePollsSameReport(t *testing.T) {
	h, p, _ := reportsHarness(t, "2026-10-18 01:30", campaignsBody(3), nil)
	pending := make([]ozonperf.ReportState, 100)
	for i := range pending {
		pending[i] = ozonperf.ReportPending
	}
	p.reports.states = pending

	res := h.exec.Run(context.Background(), reportTask(ResourceAdsStatistics, "2026-10-17"), reportsCabinet, "trace")

	assert.Equal(t, labelReportPending, res.Outcome)
	assert.True(t, h.tasks.finished.KeepCursor)
	require.Len(t, p.reports.orders, 1)
	saved := h.tasks.savedCursors[len(h.tasks.savedCursors)-1]
	assert.Contains(t, saved, ":uuid-1:")

	// Рестарт: задача с сохранённым курсором опрашивает тот же отчёт, не заказывает новый.
	h2, p2, _ := reportsHarness(t, "2026-10-18 02:00", campaignsBody(3), nil)
	tk := reportTask(ResourceAdsStatistics, "2026-10-17")
	tk.Cursor = saved
	res = h2.exec.Run(context.Background(), tk, reportsCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Empty(t, p2.reports.orders, "без нового заказа")
	assert.Equal(t, []string{"uuid-1"}, p2.reports.polled)
	assert.Equal(t, []string{"uuid-1"}, p2.reports.downloads)
}

func TestAdsReportResumeOutsideWindowStillPolls(t *testing.T) {
	h, p, _ := reportsHarness(t, "2026-10-18 06:00", campaignsBody(3), nil)
	tk := reportTask(ResourceAdsStatistics, "2026-10-17")
	tk.Cursor = reportCursor{fp: numbersFingerprint([]string{"3"}), uuid: "uuid-7", orderedAt: msk(t, "2026-10-18 04:30").Unix()}.String()

	res := h.exec.Run(context.Background(), tk, reportsCabinet, "trace")

	assert.Equal(t, "done", res.Outcome, "опрос и скачивание заказанного — в любое время")
	assert.Equal(t, []string{"uuid-7"}, p.reports.downloads)
}

func TestAdsReportFailedAtOzon(t *testing.T) {
	h, p, _ := reportsHarness(t, "2026-10-18 01:30", campaignsBody(3), nil)
	p.reports.states = []ozonperf.ReportState{ozonperf.ReportFailed}

	res := h.exec.Run(context.Background(), reportTask(ResourceAdsStatistics, "2026-10-17"), reportsCabinet, "trace")

	assert.Equal(t, labelReportWait, res.Outcome)
	assert.True(t, h.tasks.finished.NextRunAt.Equal(msk(t, "2026-10-18 02:00")))
	saved := h.tasks.savedCursors[len(h.tasks.savedCursors)-1]
	assert.NotContains(t, saved, "uuid-1", "UUID сброшен — заказать заново")
	assert.True(t, strings.HasSuffix(saved, ":1"), "ошибок подряд: 1")

	h2, p2, _ := reportsHarness(t, "2026-10-18 02:00", campaignsBody(3), nil)
	p2.reports.states = []ozonperf.ReportState{ozonperf.ReportFailed}
	tk := reportTask(ResourceAdsStatistics, "2026-10-17")
	tk.Cursor = reportCursor{fp: numbersFingerprint([]string{"3"}), fails: 2}.String()
	res = h2.exec.Run(context.Background(), tk, reportsCabinet, "trace")

	assert.Equal(t, "report_failed", res.Outcome)
	assert.Equal(t, store.StatusFailedPermanent, h2.tasks.finished.Status)
	assert.Contains(t, h2.logs.String(), `"level":"ERROR"`)
}

func TestAdsReportOrderRateLimitWaitsForNextWindow(t *testing.T) {
	h, p, _ := reportsHarness(t, "2026-10-18 01:30", campaignsBody(3), nil)
	p.reports.orderErr = &ozon.APIError{Class: ozon.ClassRateLimit, Endpoint: ozonperf.EndpointStatistics, Status: 429}

	res := h.exec.Run(context.Background(), reportTask(ResourceAdsStatistics, "2026-10-17"), reportsCabinet, "trace")

	assert.Equal(t, labelReportWait, res.Outcome)
	assert.True(t, h.tasks.finished.NextRunAt.Equal(msk(t, "2026-10-19 00:30")), "не повторять подряд")
}

func TestAdsReportAbandonedAfterThreeHours(t *testing.T) {
	h, p, _ := reportsHarness(t, "2026-10-18 04:31", campaignsBody(3), nil)
	p.reports.states = []ozonperf.ReportState{ozonperf.ReportPending}
	tk := reportTask(ResourceAdsStatistics, "2026-10-17")
	tk.Cursor = reportCursor{fp: numbersFingerprint([]string{"3"}), uuid: "uuid-7", orderedAt: msk(t, "2026-10-18 01:30").Unix()}.String()

	res := h.exec.Run(context.Background(), tk, reportsCabinet, "trace")

	assert.Equal(t, labelReportWait, res.Outcome)
	assert.True(t, h.tasks.finished.NextRunAt.Equal(msk(t, "2026-10-19 00:30")))
	assert.NotContains(t, h.tasks.savedCursors[len(h.tasks.savedCursors)-1], "uuid-7")
	assert.Contains(t, h.logs.String(), "ads report abandoned")
}

func TestAdsReportCampaignsNotLoadedWaits(t *testing.T) {
	h, p, _ := reportsHarness(t, "2026-10-18 01:30", "", nil)

	res := h.exec.Run(context.Background(), reportTask(ResourceAdsStatistics, "2026-10-17"), reportsCabinet, "trace")

	assert.Equal(t, "ads_campaigns_not_loaded", res.Outcome)
	assert.True(t, h.tasks.finished.KeepCursor)
	assert.Empty(t, p.reports.orders)
}

func TestAdsReportRevisedCampaignsRestart(t *testing.T) {
	h, p, _ := reportsHarness(t, "2026-10-18 01:30", campaignsBody(3, 4), nil)
	tk := reportTask(ResourceAdsStatistics, "2026-10-17")
	tk.Cursor = reportCursor{fp: numbersFingerprint([]string{"3"}), idx: 1}.String()

	res := h.exec.Run(context.Background(), tk, reportsCabinet, "trace")

	assert.Equal(t, "ads_campaigns_revised", res.Outcome)
	assert.False(t, h.tasks.finished.KeepCursor)
	assert.Empty(t, p.reports.orders)
}

func TestAdsReportOrderWithUnknownOutcomeKeepsReservation(t *testing.T) {
	h, p, _ := reportsHarness(t, "2026-10-18 01:30", campaignsBody(3), nil)
	p.reports.orderErr = &ozon.APIError{Class: ozon.ClassTransient, Endpoint: ozonperf.EndpointStatistics}

	res := h.exec.Run(context.Background(), reportTask(ResourceAdsStatistics, "2026-10-17"), reportsCabinet, "trace")

	assert.Equal(t, labelReportWait, res.Outcome)
	assert.True(t, h.tasks.finished.KeepCursor)
	assert.True(t, h.tasks.finished.NextRunAt.Equal(msk(t, "2026-10-18 02:30")))
	reserved := h.tasks.savedCursors[len(h.tasks.savedCursors)-1]
	assert.Contains(t, reserved, ":"+reportReserved+":", "Ozon мог принять заказ — аккаунт занят")

	// Повтор до срока резерва — без заказа, ждать срока.
	h2, p2, _ := reportsHarness(t, "2026-10-18 02:00", campaignsBody(3), nil)
	tk := reportTask(ResourceAdsStatistics, "2026-10-17")
	tk.Cursor = reserved
	res = h2.exec.Run(context.Background(), tk, reportsCabinet, "trace")
	assert.Equal(t, labelReportWait, res.Outcome)
	assert.True(t, h2.tasks.finished.NextRunAt.Equal(msk(t, "2026-10-18 02:30")))
	assert.Empty(t, p2.reports.orders)

	// После срока — резерв снят (WARNING), заказ заново.
	h3, p3, _ := reportsHarness(t, "2026-10-18 02:31", campaignsBody(3), nil)
	res = h3.exec.Run(context.Background(), tk, reportsCabinet, "trace")
	assert.Equal(t, "done", res.Outcome)
	assert.Len(t, p3.reports.orders, 1)
	assert.Contains(t, h3.logs.String(), "reservation expired")
}

func TestAdsReportRejectedOrderClearsReservation(t *testing.T) {
	h, p, _ := reportsHarness(t, "2026-10-18 01:30", campaignsBody(3), nil)
	p.reports.orderErr = &ozon.APIError{Class: ozon.ClassInvalidRequest, Endpoint: ozonperf.EndpointStatistics, Status: 400}

	res := h.exec.Run(context.Background(), reportTask(ResourceAdsStatistics, "2026-10-17"), reportsCabinet, "trace")

	assert.Equal(t, "invalid_request", res.Outcome)
	assert.NotContains(t, h.tasks.savedCursors[len(h.tasks.savedCursors)-1], reportReserved, "отказ Ozon — заказа нет")
}

func TestAdsReportOrderCannotLeaveTheWindow(t *testing.T) {
	h, p, clk := reportsHarness(t, "2026-10-18 03:59", campaignsBody(3), nil)
	clk.now = clk.now.Add(59*time.Second + 950*time.Millisecond) // до конца окна — 50 мс
	p.reports.orderHang = true

	res := h.exec.Run(context.Background(), reportTask(ResourceAdsStatistics, "2026-10-17"), reportsCabinet, "trace")

	assert.Equal(t, labelReportWait, res.Outcome, "ожидание лимитера за 04:00 — не заказ")
	assert.True(t, h.tasks.finished.NextRunAt.Equal(msk(t, "2026-10-19 00:30")))
	assert.Contains(t, h.tasks.savedCursors[len(h.tasks.savedCursors)-1], reportReserved, "запрос мог уйти — резерв остаётся")
}

func TestAdsReportRevisedCampaignsFinishOutstandingReportFirst(t *testing.T) {
	old := reportCursor{fp: numbersFingerprint([]string{"3"}), uuid: "uuid-7", orderedAt: msk(t, "2026-10-18 01:30").Unix()}.String()

	// Отчёт прежнего набора ещё не готов — ждать с тем же UUID.
	h, p, _ := reportsHarness(t, "2026-10-18 01:40", campaignsBody(3, 4), nil)
	pending := make([]ozonperf.ReportState, 100)
	for i := range pending {
		pending[i] = ozonperf.ReportPending
	}
	p.reports.states = pending
	tk := reportTask(ResourceAdsStatistics, "2026-10-17")
	tk.Cursor = old
	res := h.exec.Run(context.Background(), tk, reportsCabinet, "trace")
	assert.Equal(t, labelReportPending, res.Outcome)
	assert.True(t, h.tasks.finished.KeepCursor)
	assert.Empty(t, p.reports.orders)

	// Готов — результат отброшен, задача заново.
	h2, p2, _ := reportsHarness(t, "2026-10-18 01:50", campaignsBody(3, 4), nil)
	res = h2.exec.Run(context.Background(), tk, reportsCabinet, "trace")
	assert.Equal(t, "ads_campaigns_revised", res.Outcome)
	assert.False(t, h2.tasks.finished.KeepCursor)
	assert.Empty(t, p2.reports.downloads)
	assert.Empty(t, p2.reports.orders)
}

func TestAdsReportEmptyListWithSavedBatchesIsRevision(t *testing.T) {
	daily := map[string]string{"2026-10-17": `{"rows":[{"id":"3","views":"0","clicks":"0","moneySpent":"0"}]}`}
	h, _, _ := reportsHarness(t, "2026-10-18 01:30", campaignsBody(3), daily)
	tk := reportTask(ResourceAdsStatistics, "2026-10-17")
	tk.Cursor, tk.NextPageNo, tk.PageHashes = reportCursor{fp: numbersFingerprint([]string{"3", "4"}), idx: 1}.String(), 2, []string{"h1"}

	res := h.exec.Run(context.Background(), tk, reportsCabinet, "trace")

	assert.Equal(t, "ads_campaigns_revised", res.Outcome, "служебная страница не дописывается к прежним порциям")
	assert.Empty(t, h.raw.puts)
}

func TestAdsReportOrderAcceptedWithoutUUIDKeepsReservation(t *testing.T) {
	h, p, _ := reportsHarness(t, "2026-10-18 01:30", campaignsBody(3), nil)
	p.reports.orderErr = &ozon.APIError{Class: ozon.ClassInvalidResponse, Endpoint: ozonperf.EndpointStatistics, Status: 200}

	res := h.exec.Run(context.Background(), reportTask(ResourceAdsStatistics, "2026-10-17"), reportsCabinet, "trace")

	assert.Equal(t, labelReportWait, res.Outcome, "не постоянный отказ")
	assert.True(t, h.tasks.finished.NextRunAt.Equal(msk(t, "2026-10-18 02:30")), "до срока резерва")
	assert.Contains(t, h.tasks.savedCursors[len(h.tasks.savedCursors)-1], reportReserved)
}

func TestAdsReportOrderOversized5xxKeepsReservation(t *testing.T) {
	h, p, _ := reportsHarness(t, "2026-10-18 01:30", campaignsBody(3), nil)
	p.reports.orderErr = &ozon.APIError{Class: ozon.ClassInvalidResponse, Endpoint: ozonperf.EndpointStatistics, Status: 500, Err: ozon.ErrBodyTooLarge}

	res := h.exec.Run(context.Background(), reportTask(ResourceAdsStatistics, "2026-10-17"), reportsCabinet, "trace")

	assert.Equal(t, labelReportWait, res.Outcome)
	assert.NotEqual(t, store.StatusFailedPermanent, h.tasks.finished.Status)
	assert.True(t, h.tasks.finished.KeepCursor)
	assert.Contains(t, h.tasks.savedCursors[len(h.tasks.savedCursors)-1], reportReserved)
}

func TestAdsReportStatusErrorsKeepUUID(t *testing.T) {
	for name, statusErr := range map[string]error{
		"oversized 500": &ozon.APIError{Class: ozon.ClassInvalidResponse, Endpoint: ozonperf.EndpointStatisticsStatus, Status: 500, Err: ozon.ErrBodyTooLarge},
		"bad state":     &ozon.APIError{Class: ozon.ClassInvalidResponse, Endpoint: ozonperf.EndpointStatisticsStatus, Status: 200},
		"auth":          &ozon.APIError{Class: ozon.ClassAuth, Endpoint: ozonperf.EndpointStatisticsStatus, Status: 401},
	} {
		h, p, _ := reportsHarness(t, "2026-10-18 02:00", campaignsBody(3), nil)
		p.reports.statusErr = statusErr
		tk := reportTask(ResourceAdsStatistics, "2026-10-17")
		tk.Cursor = reportCursor{fp: numbersFingerprint([]string{"3"}), uuid: "uuid-7", orderedAt: msk(t, "2026-10-18 01:30").Unix()}.String()

		res := h.exec.Run(context.Background(), tk, reportsCabinet, "trace")

		assert.Equal(t, labelReportPending, res.Outcome, name)
		assert.True(t, h.tasks.finished.KeepCursor, name)
		assert.Empty(t, p.reports.orders, name)
	}

	// Опрос недоступен дольше срока — отчёт брошен, следующее окно.
	h, p, _ := reportsHarness(t, "2026-10-18 04:31", campaignsBody(3), nil)
	p.reports.statusErr = &ozon.APIError{Class: ozon.ClassTransient, Endpoint: ozonperf.EndpointStatisticsStatus, Status: 503}
	tk := reportTask(ResourceAdsStatistics, "2026-10-17")
	tk.Cursor = reportCursor{fp: numbersFingerprint([]string{"3"}), uuid: "uuid-7", orderedAt: msk(t, "2026-10-18 01:30").Unix()}.String()
	res := h.exec.Run(context.Background(), tk, reportsCabinet, "trace")
	assert.Equal(t, labelReportWait, res.Outcome)
	assert.NotContains(t, h.tasks.savedCursors[len(h.tasks.savedCursors)-1], "uuid-7")
}

func TestAdsReportBrokenCampaignsRawFinishesOutstandingReportFirst(t *testing.T) {
	h, p, _ := reportsHarness(t, "2026-10-18 02:00", `{"broken":true}`, nil)
	p.reports.states = []ozonperf.ReportState{ozonperf.ReportPending, ozonperf.ReportPending}
	p.reports.states = append(p.reports.states, make([]ozonperf.ReportState, 100)...)
	for i := range p.reports.states {
		p.reports.states[i] = ozonperf.ReportPending
	}
	tk := reportTask(ResourceAdsStatistics, "2026-10-17")
	tk.Cursor = reportCursor{fp: "x", uuid: "uuid-7", orderedAt: msk(t, "2026-10-18 01:30").Unix()}.String()

	res := h.exec.Run(context.Background(), tk, reportsCabinet, "trace")

	assert.Equal(t, labelReportPending, res.Outcome, "UUID не сбрасывается постоянной ошибкой")
	assert.True(t, h.tasks.finished.KeepCursor)

	h2, _, _ := reportsHarness(t, "2026-10-18 02:10", `{"broken":true}`, nil)
	res = h2.exec.Run(context.Background(), tk, reportsCabinet, "trace")
	assert.Equal(t, "ads_raw_invalid", res.Outcome, "отчёт готов — теперь постоянная ошибка")
}
