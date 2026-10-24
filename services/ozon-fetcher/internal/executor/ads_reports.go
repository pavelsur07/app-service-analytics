package executor

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"strconv"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozonperf"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

// Отчёты по товарам кампаний — асинхронные
// (docs/tasks/marketplace-data-ingestion/stages/ozon-7-ads-reports.md).
const (
	// ResourceAdsStatistics — отчёт за день period_key (вчера).
	ResourceAdsStatistics = "ozon.ads.statistics"
	// ResourceAdsStatisticsTail — отчёт диапазоном AdsReportTailDays дней до
	// period_key (день прохода).
	ResourceAdsStatisticsTail = "ozon.ads.statistics.tail"

	// AdsReportTailDays — длина диапазона хвоста.
	AdsReportTailDays = 14
	// adsReportMaxFailures — отчёт подряд с ошибкой Ozon — failed_permanent.
	adsReportMaxFailures = 3
)

// emptyAdsReportPage — служебная страница без активных SKU-кампаний.
const emptyAdsReportPage = `{"reports":[]}`

var (
	// errAdsReportBusy — у аккаунта уже заказан отчёт другой задачей.
	errAdsReportBusy = errors.New("executor: another ads report of the cabinet is in flight")
	// errAdsReportPending — отчёт не готов к концу попытки; UUID сохранён.
	errAdsReportPending = errors.New("executor: ads report is not ready yet")
	// errAdsReportFailed — Ozon отдал ошибку формирования adsReportMaxFailures раз подряд.
	errAdsReportFailed = errors.New("executor: ads report failed repeatedly")
	// errAdsCampaignsRevised — список порций изменился с сохранённого курсора.
	errAdsCampaignsRevised = errors.New("executor: ads campaigns changed since the saved report")
	// errAdsCampaignsNotLoaded — кампании дня ещё не загружены.
	errAdsCampaignsNotLoaded = errors.New("executor: ads campaigns of the day are not loaded yet")
	// errAdsRawInvalid — сохранённая страница кампаний повреждена или не разбирается.
	errAdsRawInvalid = errors.New("executor: stored ads campaigns page is not readable")
)

// adsReportWait — задача ждёт момента at (окно заказа, повтор после ошибки).
type adsReportWait struct {
	at     time.Time
	reason string
}

func (e *adsReportWait) Error() string {
	return "executor: ads report waits until " + e.at.Format(time.RFC3339) + ": " + e.reason
}

// IsAdsReportResource — ресурс асинхронных отчётов рекламы.
func IsAdsReportResource(resourceType string) bool {
	return resourceType == ResourceAdsStatistics || resourceType == ResourceAdsStatisticsTail
}

// reportCursor — «отпечаток порций:индекс порции:UUID:время заказа:ошибок подряд».
type reportCursor struct {
	fp        string
	idx       int
	uuid      string
	orderedAt int64
	fails     int
}

func (c reportCursor) String() string {
	return fmt.Sprintf("%s:%d:%s:%d:%d", c.fp, c.idx, c.uuid, c.orderedAt, c.fails)
}

func parseReportCursor(s string) (reportCursor, bool) {
	parts := strings.Split(s, ":")
	if len(parts) != 5 {
		return reportCursor{}, false
	}
	idx, err1 := strconv.Atoi(parts[1])
	at, err2 := strconv.ParseInt(parts[3], 10, 64)
	fails, err3 := strconv.Atoi(parts[4])
	if err1 != nil || err2 != nil || err3 != nil || idx < 0 {
		return reportCursor{}, false
	}
	return reportCursor{fp: parts[0], idx: idx, uuid: parts[2], orderedAt: at, fails: fails}, true
}

// adsWindow — окно заказа отчётов дня: [00:30; 04:00) МСК. Symfony
// заказывает с 04:30 и днём — окно не отнимает его слоты.
func (e *Executor) adsWindow(now time.Time) (start, end time.Time) {
	local := now.In(e.cfg.Location)
	day := time.Date(local.Year(), local.Month(), local.Day(), 0, 0, 0, 0, e.cfg.Location)
	return day.Add(e.cfg.AdsReportWindowFrom), day.Add(e.cfg.AdsReportWindowTo)
}

// nextAdsWindow — начало ближайшего окна после now (текущее, если не началось).
func (e *Executor) nextAdsWindow(now time.Time) time.Time {
	start, _ := e.adsWindow(now)
	if now.Before(start) {
		return start
	}
	return start.AddDate(0, 0, 1)
}

// adsReports — отчёты по порциям ≤ 10 активных SKU-кампаний; страница = отчёт.
func (r *taskRun) adsReports(ctx context.Context, creds ozonperf.Credentials, cabinet connections.Cabinet) error {
	e := r.exec
	if r.cursor == "" && len(r.hashes) > 0 {
		return nil
	}
	from, to, err := r.reportRange()
	if err != nil {
		return err
	}
	ids, err := r.reportCampaigns(ctx, cabinet, from, to)
	if errors.Is(err, errAdsRawInvalid) {
		// Постоянная ошибка сбросит курсор: заказанный отчёт сначала доводится
		// до конца, чтобы аккаунт не получил второй отчёт.
		if saved, ok := parseReportCursor(r.cursor); ok && saved.uuid != "" {
			if retireErr := r.retireReport(ctx, creds, saved); !errors.Is(retireErr, errAdsCampaignsRevised) {
				return retireErr
			}
		}
		return err
	}
	if err != nil {
		return err
	}
	fp := numbersFingerprint(ids)
	cur := reportCursor{fp: fp}
	if r.cursor != "" {
		saved, ok := parseReportCursor(r.cursor)
		if !ok {
			return errAdsCampaignsRevised
		}
		if saved.fp != fp || len(ids) == 0 {
			// Набор порций изменился: заказанный отчёт прежнего набора сначала
			// доводится до конца (не держать второй отчёт аккаунта), затем — заново.
			return r.retireReport(ctx, creds, saved)
		}
		cur = saved
	}
	if len(ids) == 0 {
		return r.store(ctx, ozon.Page{No: r.nextPage, Body: []byte(emptyAdsReportPage), ContentType: "application/json"})
	}

	for cur.idx*ozonperf.MaxReportCampaigns < len(ids) {
		chunk := ids[cur.idx*ozonperf.MaxReportCampaigns : min((cur.idx+1)*ozonperf.MaxReportCampaigns, len(ids))]
		if cur.uuid == reportReserved {
			if err := r.expireReservation(ctx, &cur); err != nil {
				return err
			}
		}
		if cur.uuid == "" {
			if err := r.orderReport(ctx, creds, &cur, chunk, from, to); err != nil {
				return err
			}
		}
		st, err := r.awaitReport(ctx, creds, &cur)
		if err != nil {
			return err
		}
		page, err := e.perf.DownloadReport(ctx, r.task.Account, creds, cur.uuid, st.Link)
		if err != nil {
			return err
		}
		next := reportCursor{fp: fp, idx: cur.idx + 1}
		page.No, page.Cursor = r.nextPage, ""
		if next.idx*ozonperf.MaxReportCampaigns < len(ids) {
			page.Cursor = next.String()
		}
		if err := r.store(ctx, page); err != nil {
			return err
		}
		r.log.Info("ads report saved", slog.String("stage", "fetch"), slog.Int("batch", cur.idx),
			slog.Int("campaigns", len(chunk)), slog.Int("bytes", len(page.Body)), slog.String("content_type", page.ContentType))
		cur = next
	}
	return nil
}

// reportReserved — UUID курсора, пока заказ отправлен, а номер отчёта не
// получен: Ozon мог принять заказ (сбой ответа, рестарт). Резерв держит
// аккаунт занятым до AdsReportReservation после заказа.
const reportReserved = "reserved"

// retireReport — смена набора порций при сохранённом курсоре: отчёт прежнего
// набора (или резерв заказа) доводится до конца, результат отбрасывается,
// затем задача — заново.
func (r *taskRun) retireReport(ctx context.Context, creds ozonperf.Credentials, saved reportCursor) error {
	switch saved.uuid {
	case "":
	case reportReserved:
		if err := r.expireReservation(ctx, &saved); err != nil {
			return err
		}
	default:
		if _, err := r.awaitReport(ctx, creds, &saved); err != nil {
			var wait *adsReportWait
			if !errors.As(err, &wait) {
				// Не готов, сбой — ждём с прежним курсором (UUID сохранён).
				return err
			}
			// Ошибка Ozon или брошен — отчёта больше нет, можно заново.
		}
	}
	return errAdsCampaignsRevised
}

// expireReservation — резерв заказа старше AdsReportReservation снимается
// (WARNING: принятый Ozon отчёт мог потеряться), иначе — ждать его срока.
func (r *taskRun) expireReservation(ctx context.Context, cur *reportCursor) error {
	e := r.exec
	until := time.Unix(cur.orderedAt, 0).Add(e.cfg.AdsReportReservation)
	if e.now().Before(until) {
		return &adsReportWait{at: until, reason: "report order outcome unknown"}
	}
	r.log.Warn("ads report order outcome unknown, reservation expired", slog.String("stage", "fetch"), slog.Int("batch", cur.idx))
	cur.uuid, cur.orderedAt = "", 0
	return r.saveCursor(ctx, *cur)
}

// orderReport заказывает отчёт порции: только в окне (и запрос уходит до его
// конца — дедлайн покрывает токен и лимитер) и только если у аккаунта нет
// другого заказанного отчёта. Перед заказом — резерв в курсоре, номер
// отчёта — сразу после.
func (r *taskRun) orderReport(ctx context.Context, creds ozonperf.Credentials, cur *reportCursor, chunk []string, from, to time.Time) error {
	e := r.exec
	now := e.now()
	start, end := e.adsWindow(now)
	if now.Before(start) || !now.Before(end) {
		return &adsReportWait{at: e.nextAdsWindow(now), reason: "outside the order window"}
	}
	busy, err := e.tasks.AdsReportInFlight(ctx, r.task.TaskKey)
	if err != nil {
		return &storeError{err}
	}
	if busy {
		return errAdsReportBusy
	}
	cur.uuid, cur.orderedAt = reportReserved, now.Unix()
	if err := r.saveCursor(ctx, *cur); err != nil {
		return err
	}
	orderCtx, cancel := context.WithTimeout(ctx, end.Sub(now))
	id, err := e.perf.OrderReport(orderCtx, r.task.Account, creds, chunk, from, to)
	cancel()
	if err != nil {
		var apiErr *ozon.APIError
		switch {
		case ctx.Err() != nil:
			return ctx.Err()
		case errors.Is(err, context.DeadlineExceeded):
			// Окно закончилось в ожидании лимитера или ответа — резерв
			// остаётся: запрос мог уйти.
			return &adsReportWait{at: e.nextAdsWindow(e.now()), reason: "order window ended"}
		case errors.As(err, &apiErr) && apiErr.Status >= 400 && apiErr.Status < 500:
			// Ozon ответил отказом (4xx) — заказа нет, резерв снимается.
			cur.uuid, cur.orderedAt = "", 0
			if saveErr := r.saveCursor(ctx, *cur); saveErr != nil {
				return saveErr
			}
			if apiErr.Class == ozon.ClassRateLimit {
				// Лимит отчётов аккаунта общий с Symfony: не повторять подряд —
				// следующее окно.
				return &adsReportWait{at: start.AddDate(0, 0, 1), reason: "report order rate limited"}
			}
			return err
		default:
			// Сбой сети, 5xx, 2xx без номера отчёта: Ozon мог принять заказ —
			// резерв до срока, затем заказ заново.
			r.log.Warn("ads report order outcome unknown", slog.String("stage", "fetch"), slog.String("error", err.Error()))
			return &adsReportWait{at: time.Unix(cur.orderedAt, 0).Add(e.cfg.AdsReportReservation), reason: "order outcome unknown"}
		}
	}
	cur.uuid = id
	if err := r.saveCursor(ctx, *cur); err != nil {
		return err
	}
	r.log.Info("ads report ordered", slog.String("stage", "fetch"), slog.Int("batch", cur.idx), slog.Int("campaigns", len(chunk)))
	return nil
}

// awaitReport опрашивает отчёт до готовности в пределах попытки.
func (r *taskRun) awaitReport(ctx context.Context, creds ozonperf.Credentials, cur *reportCursor) (ozonperf.ReportStatus, error) {
	e := r.exec
	deadline := e.now().Add(e.cfg.AdsReportAttempt)
	for {
		st, err := e.perf.ReportStatus(ctx, r.task.Account, creds, cur.uuid)
		if err != nil {
			if ctx.Err() != nil {
				return st, ctx.Err()
			}
			// Отчёт заказан: любая ошибка опроса (сеть, 5xx, 401, битый ответ)
			// оставляет UUID — аккаунт занят, опрос повторится; срок «брошен»
			// проверяется на каждом опросе.
			r.log.Warn("ads report status failed, UUID kept", slog.String("stage", "fetch"), slog.String("error", err.Error()))
			if e.now().Sub(time.Unix(cur.orderedAt, 0)) >= e.cfg.AdsReportAbandon {
				return st, r.abandonReport(ctx, cur, "status unavailable")
			}
			return st, errAdsReportPending
		}
		switch st.State {
		case ozonperf.ReportReady:
			return st, nil
		case ozonperf.ReportFailed:
			cur.fails++
			r.log.Warn("ads report failed at Ozon", slog.String("stage", "fetch"), slog.String("state", st.Raw), slog.Int("failures", cur.fails))
			if cur.fails >= adsReportMaxFailures {
				return st, errAdsReportFailed
			}
			cur.uuid, cur.orderedAt = "", 0
			if err := r.saveCursor(ctx, *cur); err != nil {
				return st, err
			}
			return st, &adsReportWait{at: e.now().Add(e.cfg.AdsReportRetry), reason: "report failed, order again"}
		}
		now := e.now()
		if now.Sub(time.Unix(cur.orderedAt, 0)) >= e.cfg.AdsReportAbandon {
			return st, r.abandonReport(ctx, cur, st.Raw)
		}
		if !now.Before(deadline) {
			return st, errAdsReportPending
		}
		if err := e.sleep(ctx, e.cfg.AdsReportPoll); err != nil {
			return st, err
		}
	}
}

// abandonReport — отчёт не готов за AdsReportAbandon: UUID брошен, заказ —
// в следующем окне (WARNING).
func (r *taskRun) abandonReport(ctx context.Context, cur *reportCursor, state string) error {
	r.log.Warn("ads report abandoned: not ready in time", slog.String("stage", "fetch"), slog.String("state", state))
	cur.uuid, cur.orderedAt = "", 0
	if err := r.saveCursor(ctx, *cur); err != nil {
		return err
	}
	return &adsReportWait{at: r.exec.nextAdsWindow(r.exec.now()), reason: "report abandoned"}
}

func (r *taskRun) saveCursor(ctx context.Context, cur reportCursor) error {
	if err := r.exec.tasks.SaveCursor(ctx, r.task.TaskKey, r.task.AttemptID, cur.String()); err != nil {
		if errors.Is(err, store.ErrLeaseLost) {
			return err
		}
		return &storeError{err}
	}
	r.cursor = cur.String()
	return nil
}

// reportRange — дни отчёта: день задачи или хвост до дня прохода.
func (r *taskRun) reportRange() (time.Time, time.Time, error) {
	day, err := time.ParseInLocation(time.DateOnly, r.task.PeriodKey, r.exec.cfg.Location)
	if err != nil {
		return time.Time{}, time.Time{}, fmt.Errorf("%w: %q", errBadPeriod, r.task.PeriodKey)
	}
	if r.task.ResourceType == ResourceAdsStatisticsTail {
		return day.AddDate(0, 0, -AdsReportTailDays), day.AddDate(0, 0, -1), nil
	}
	return day, day, nil
}

// reportCampaigns — SKU-кампании из снимка кампаний дня исполнения, только
// активные в днях отчёта по дневной статистике; форма статистики незнакома
// или день не загружен — все SKU-кампании (как Symfony).
func (r *taskRun) reportCampaigns(ctx context.Context, cabinet connections.Cabinet, from, to time.Time) ([]string, error) {
	e := r.exec
	if !cabinet.Supports(ResourceAdsCampaigns) {
		return nil, errResourceNotEnabled
	}
	today := e.now().In(e.cfg.Location).Format(time.DateOnly)
	content, err := e.tasks.ContentPages(ctx, store.TaskKey{Account: r.task.Account, ResourceType: ResourceAdsCampaigns, PeriodKey: today})
	if errors.Is(err, store.ErrNoContent) {
		return nil, errAdsCampaignsNotLoaded
	}
	if errors.Is(err, store.ErrMissingObject) {
		return nil, fmt.Errorf("%w: %v", errAdsRawInvalid, err)
	}
	if err != nil {
		return nil, &storeError{err}
	}
	var sku []string
	err = r.readContent(ctx, content, ResourceAdsCampaigns, errAdsRawInvalid, func(body []byte) error {
		ids, err := ozonperf.SKUCampaigns(body)
		sku = append(sku, ids...)
		return err
	})
	if err != nil {
		return nil, err
	}
	active, known := r.activeCampaigns(ctx, cabinet, from, to)
	if !known {
		return sku, nil
	}
	out := make([]string, 0, len(sku))
	for _, id := range sku {
		if active[id] {
			out = append(out, id)
		}
	}
	r.log.Info("ads report campaigns", slog.String("stage", "fetch"), slog.Int("sku", len(sku)), slog.Int("active", len(out)))
	return out, nil
}

// activeCampaigns — кампании с показами, кликами или расходом в днях
// отчёта; known=false — статистика хотя бы одного дня недоступна.
func (r *taskRun) activeCampaigns(ctx context.Context, cabinet connections.Cabinet, from, to time.Time) (map[string]bool, bool) {
	e := r.exec
	if !cabinet.Supports(ResourceAdsDaily) {
		return nil, false
	}
	active := map[string]bool{}
	for d := from; !d.After(to); d = d.AddDate(0, 0, 1) {
		content, err := e.tasks.ContentPages(ctx, store.TaskKey{Account: r.task.Account, ResourceType: ResourceAdsDaily, PeriodKey: d.Format(time.DateOnly)})
		if err != nil {
			r.log.Info("ads report: daily statistics unavailable, all SKU campaigns", slog.String("stage", "fetch"), slog.String("day", d.Format(time.DateOnly)))
			return nil, false
		}
		known := true
		readErr := r.readContent(ctx, content, ResourceAdsDaily, errAdsRawInvalid, func(body []byte) error {
			ids, ok := ozonperf.ActiveCampaigns(body)
			if !ok {
				known = false
				return nil
			}
			for id := range ids {
				active[id] = true
			}
			return nil
		})
		if readErr != nil || !known {
			r.log.Info("ads report: daily statistics shape unknown, all SKU campaigns", slog.String("stage", "fetch"), slog.String("day", d.Format(time.DateOnly)))
			return nil, false
		}
	}
	return active, true
}
