package executor

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/rawstore"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

// labelSnapshotMissed — снимок за прошедший день не загружен (WARNING, не ERROR).
const labelSnapshotMissed = "snapshot_missed"

// Ожидания отчётов рекламы — штатные исходы (INFO).
const (
	labelReportPending = "report_pending"
	labelReportWait    = "report_wait"
	labelReportBusy    = "report_busy"
)

// outcome сопоставляет результат загрузки строке таблицы исходов (stage-9.md).
// Побочные действия (пауза кабинета, стоп ресурса, обновление кабинетов)
// выполняются здесь же — до записи итога задачи.
func (e *Executor) outcome(parent, taskCtx context.Context, task store.Task, run *taskRun, err error, log *slog.Logger) store.Outcome {
	now := e.now()
	// Побочные записи переживают отмену задачи, но ограничены по времени:
	// зависшее соединение с БД не задерживает shutdown и не переживает lease.
	side, cancelSide := context.WithTimeout(context.WithoutCancel(parent), e.cfg.FinishTimeout)
	defer cancelSide()

	if err == nil {
		label := "done"
		hash := contentHash(run.hashes)
		if hash == task.ContentHash {
			label = "unchanged"
		}
		// Успех с ключом seller сбрасывает счётчик отказов кабинета, даже если
		// эта задача сама 401 не получала (no-op, когда сбрасывать нечего);
		// у рекламы свой ключ — счётчик seller она не трогает.
		if !IsAdsResource(task.ResourceType) {
			e.sideEffect(log, "resume connection", e.tasks.ResumeConnection(side, task.Account))
		}
		return store.Outcome{Status: store.StatusDone, Label: label, ContentHash: hash}
	}

	if errors.Is(context.Cause(taskCtx), errLeaseLost) || errors.Is(err, store.ErrLeaseLost) {
		return store.Outcome{Status: store.StatusPending, Label: "lease_lost", KeepCursor: true, NextRunAt: now}
	}
	if parent.Err() != nil && errors.Is(err, parent.Err()) {
		// Graceful shutdown: задача возвращается в очередь с курсором.
		return store.Outcome{Status: store.StatusPending, Label: "released", KeepCursor: true, NextRunAt: now}
	}
	if errors.Is(err, errNoCredentials) {
		return store.Outcome{Status: store.StatusPending, Label: "no_credentials", ErrorClass: "no_credentials", KeepCursor: true, NextRunAt: now.Add(e.cfg.NoCredentialsIn)}
	}
	if errors.Is(err, errResourceNotEnabled) {
		// Задача ждёт: ресурс могут включить снова, курсор сохраняется.
		return store.Outcome{Status: store.StatusPending, Label: "resource_disabled", ErrorClass: "resource_disabled", KeepCursor: true, NextRunAt: now.Add(e.cfg.NoCredentialsIn)}
	}
	if errors.Is(err, errOrdersNotLoaded) {
		delay, ok := e.cfg.NotReadyDelay[task.ResourceType]
		if !ok {
			delay = time.Hour
		}
		return store.Outcome{Status: store.StatusNotReady, Label: "orders_not_loaded", ErrorClass: "orders_not_loaded", NextRunAt: now.Add(delay)}
	}
	if errors.Is(err, errOrdersRevised) {
		return e.retryable(task, "orders_revised", now, false)
	}
	if errors.Is(err, errOrdersRawInvalid) {
		log.Error("stored orders page is not readable", slog.String("stage", "fetch"), slog.String("error", err.Error()))
		return store.Outcome{Status: store.StatusFailedPermanent, Label: "orders_raw_invalid", ErrorClass: "orders_raw_invalid", NextRunAt: now}
	}
	var wait *adsReportWait
	if errors.As(err, &wait) {
		return store.Outcome{Status: store.StatusPending, Label: labelReportWait, ErrorClass: labelReportWait, KeepCursor: true, NextRunAt: wait.at}
	}
	if errors.Is(err, errAdsReportBusy) {
		return store.Outcome{Status: store.StatusPending, Label: labelReportBusy, ErrorClass: labelReportBusy, KeepCursor: true, NextRunAt: now.Add(e.cfg.AdsReportBusy)}
	}
	if errors.Is(err, errAdsReportPending) {
		return store.Outcome{Status: store.StatusPending, Label: labelReportPending, ErrorClass: labelReportPending, KeepCursor: true, NextRunAt: now.Add(e.cfg.AdsReportBusy)}
	}
	if errors.Is(err, errAdsCampaignsNotLoaded) {
		return store.Outcome{Status: store.StatusPending, Label: "ads_campaigns_not_loaded", ErrorClass: "ads_campaigns_not_loaded", KeepCursor: true, NextRunAt: now.Add(e.cfg.AdsCampaignsWait)}
	}
	if errors.Is(err, errAdsCampaignsRevised) {
		return e.retryable(task, "ads_campaigns_revised", now, false)
	}
	if errors.Is(err, errAdsReportFailed) {
		return store.Outcome{Status: store.StatusFailedPermanent, Label: "report_failed", ErrorClass: "report_failed", NextRunAt: now}
	}
	if errors.Is(err, errAdsRawInvalid) {
		log.Error("stored ads campaigns page is not readable", slog.String("stage", "fetch"), slog.String("error", err.Error()))
		return store.Outcome{Status: store.StatusFailedPermanent, Label: "ads_raw_invalid", ErrorClass: "ads_raw_invalid", NextRunAt: now}
	}
	var early *snapshotEarlyError
	if errors.As(err, &early) {
		return store.Outcome{Status: store.StatusPending, Label: "snapshot_early", ErrorClass: "snapshot_early", KeepCursor: true, NextRunAt: early.at}
	}
	if errors.Is(err, errSnapshotMissed) {
		return store.Outcome{Status: store.StatusFailedPermanent, Label: labelSnapshotMissed, ErrorClass: labelSnapshotMissed, NextRunAt: now}
	}
	if errors.Is(err, errCatalogNotLoaded) {
		delay, ok := e.cfg.NotReadyDelay[task.ResourceType]
		if !ok {
			delay = time.Hour
		}
		return store.Outcome{Status: store.StatusNotReady, Label: "catalog_not_loaded", ErrorClass: "catalog_not_loaded", NextRunAt: now.Add(delay)}
	}
	if errors.Is(err, errCatalogRevised) {
		return e.retryable(task, "catalog_revised", now, false)
	}
	if errors.Is(err, errCatalogRawInvalid) {
		log.Error("stored product list page is not readable", slog.String("stage", "fetch"), slog.String("error", err.Error()))
		return store.Outcome{Status: store.StatusFailedPermanent, Label: "catalog_raw_invalid", ErrorClass: "catalog_raw_invalid", NextRunAt: now}
	}
	// Ресурс или период задачи вне формы сегмента ключа: повтор той же задачи
	// соберёт тот же ключ, поэтому это постоянная ошибка, а не сбой хранилища.
	if errors.Is(err, errUnknownResource) || errors.Is(err, errBadPeriod) || errors.Is(err, rawstore.ErrInvalidKey) {
		return store.Outcome{Status: store.StatusFailedPermanent, Label: "invalid_task", ErrorClass: "invalid_task", NextRunAt: now}
	}
	var se *storeError
	if errors.As(err, &se) {
		return e.retryable(task, "store", now, true)
	}

	var apiErr *ozon.APIError
	if !errors.As(err, &apiErr) {
		// Неожиданная ошибка (например, дедлайн без класса): повтор с backoff.
		return e.retryable(task, "unexpected", now, true)
	}

	switch apiErr.Class {
	case ozon.ClassRateLimit:
		pause := apiErr.RetryAfter
		if pause <= 0 {
			pause = e.cfg.RateLimitPause
		}
		return store.Outcome{Status: store.StatusPending, Label: string(apiErr.Class), ErrorClass: string(apiErr.Class), HTTPStatus: apiErr.Status, KeepCursor: true, NextRunAt: now.Add(pause)}

	case ozon.ClassTransient:
		out := e.retryable(task, string(apiErr.Class), now, true)
		out.HTTPStatus = apiErr.Status
		return out

	case ozon.ClassAuth:
		if apiErr.Status == http.StatusForbidden {
			return e.forbidden(side, task, run, apiErr, now, log)
		}
		if IsAdsResource(task.ResourceType) {
			// Ключ Performance: пауза только этой задачи рекламы — продажи
			// кабинета идут; подряд второй отказ — длинная пауза + ERROR.
			_, refreshErr := e.refresher.ForceRefresh(side)
			e.sideEffect(log, "refresh connections", refreshErr)
			pause := e.cfg.AuthRetryPause
			if task.LastErrorClass == string(ozon.ClassAuth) {
				pause = e.cfg.AuthSuspend
				log.Error("ozon performance rejected cabinet keys again, ads task paused",
					slog.String("stage", "fetch"), slog.Int("http_status", apiErr.Status),
					slog.Time("paused_until", now.Add(pause)))
			}
			return store.Outcome{Status: store.StatusFailedRetryable, Label: string(apiErr.Class), ErrorClass: string(apiErr.Class), HTTPStatus: apiErr.Status, KeepCursor: true, NextRunAt: now.Add(pause)}
		}
		// Счётчик подряд — по кабинету: первая пауза короткая (снимок с новым
		// ключом успевает обновиться), со второго отказа — длинная + ERROR.
		failures, err := e.tasks.RecordAuthFailure(side, task.Account, now, func(n int) time.Duration {
			if n >= 2 {
				return e.cfg.AuthSuspend
			}
			return e.cfg.AuthRetryPause
		})
		if err != nil {
			// Пауза не записана — кабинет не должен сразу уйти в новый круг 401.
			e.sideEffect(log, "record auth failure", err)
			out := e.retryable(task, string(apiErr.Class), now, true)
			out.HTTPStatus = apiErr.Status
			return out
		}
		_, refreshErr := e.refresher.ForceRefresh(side)
		e.sideEffect(log, "refresh connections", refreshErr)
		if failures >= 2 {
			log.Error("ozon rejected cabinet keys again, cabinet suspended",
				slog.String("stage", "fetch"), slog.Int("http_status", apiErr.Status), slog.Int("auth_failures", failures),
				slog.Time("suspended_until", now.Add(e.cfg.AuthSuspend)))
		}
		return store.Outcome{Status: store.StatusPending, Label: string(apiErr.Class), ErrorClass: string(apiErr.Class), HTTPStatus: apiErr.Status, KeepCursor: true, NextRunAt: now}

	case ozon.ClassObsoleteEndpoint:
		if err := e.tasks.StopResourceAllConnections(side, task.ResourceType, apiErr.Endpoint); err != nil {
			// Стоп не записан — задача повторится и попробует остановить ресурс снова.
			e.sideEffect(log, "stop resource", err)
			out := e.retryable(task, string(apiErr.Class), now, false)
			out.HTTPStatus = apiErr.Status
			return out
		}
		log.Error("ozon endpoint is obsolete, resource stopped for all cabinets",
			slog.String("stage", "fetch"), slog.String("endpoint", apiErr.Endpoint), slog.Int("code", apiErr.Code),
			slog.String("message", apiErr.Message))
		return store.Outcome{Status: store.StatusFailedPermanent, Label: string(apiErr.Class), ErrorClass: string(apiErr.Class), HTTPStatus: apiErr.Status, NextRunAt: now}

	case ozon.ClassNotReady:
		delay, ok := e.cfg.NotReadyDelay[task.ResourceType]
		if !ok {
			delay = time.Hour
		}
		return store.Outcome{Status: store.StatusNotReady, Label: string(apiErr.Class), ErrorClass: string(apiErr.Class), HTTPStatus: apiErr.Status, NextRunAt: now.Add(delay)}

	case ozon.ClassInvalidRequest:
		if (IsPostingResource(task.ResourceType) || task.ResourceType == ResourceProductsList || IsStocksResource(task.ResourceType)) && (task.Cursor != "" || run.cursor != "") {
			// Запрос шёл с курсором — прежней попытки (после паузы 429, backoff,
			// рестарта) или своей предыдущей страницы: курсор Ozon мог истечь.
			// Повтор окна с первой страницы, а не потеря дня.
			out := e.retryable(task, "stale_cursor", now, false)
			out.HTTPStatus = apiErr.Status
			return out
		}
		return store.Outcome{Status: store.StatusFailedPermanent, Label: string(apiErr.Class), ErrorClass: string(apiErr.Class), HTTPStatus: apiErr.Status, NextRunAt: now}

	default: // invalid_response
		return store.Outcome{Status: store.StatusFailedPermanent, Label: string(apiErr.Class), ErrorClass: string(apiErr.Class), HTTPStatus: apiErr.Status, NextRunAt: now}
	}
}

// Исходы 403 (ADR-043 п. 5).
const (
	labelAccessRestricted = "access_restricted"
	labelForbidden        = "forbidden"
)

// forbidden — 403 Seller или Performance. Метод, который этим же ключом уже
// отвечал 200 (успешная попытка ресурса после последнего изменения ключей в
// выдаче), — признак ограничения доступа Ozon: загрузка всех подключений
// останавливается до ручного снятия, потому что ограничение ложится на
// Client ID и задевает загрузку PHP. Отзыв или урезание ключа после успешной
// загрузки от блокировки неотличимы и намеренно считаются признаком. 403 на
// методе без прежнего 200 — у ключа нет роли на метод: пауза только этого
// ресурса подключения, остальные идут.
func (e *Executor) forbidden(side context.Context, task store.Task, run *taskRun, apiErr *ozon.APIError, now time.Time, log *slog.Logger) store.Outcome {
	retry := func() store.Outcome {
		out := e.retryable(task, string(apiErr.Class), now, true)
		out.HTTPStatus = apiErr.Status
		return out
	}
	loaded, err := e.tasks.SucceededSince(side, task.Account, task.ResourceType, run.cabinet.UpdatedAt)
	if err != nil {
		// Признак не прочитан — повтор с backoff, решение — на следующем 403.
		e.sideEffect(log, "read prior success", err)
		return retry()
	}
	if loaded {
		halted, err := e.tasks.HaltAllConnections(side, task.Account, task.ResourceType, "403 on a method this key already loaded")
		if err != nil {
			e.sideEffect(log, "halt service", err)
			return retry()
		}
		if halted {
			log.Error("ozon answered 403 on a method this key already loaded: possible access restriction, loading halted for all connections until ozon-fetcher -resume-halt",
				slog.String("stage", "fetch"), slog.String("endpoint", apiErr.Endpoint), slog.Int("http_status", apiErr.Status))
		}
		return store.Outcome{Status: store.StatusFailedRetryable, Label: labelAccessRestricted, ErrorClass: labelAccessRestricted, HTTPStatus: apiErr.Status, KeepCursor: true, NextRunAt: now}
	}
	until := now.Add(e.cfg.ForbiddenPause)
	if err := e.tasks.PauseResource(side, task.Account, task.ResourceType, until, labelForbidden); err != nil {
		e.sideEffect(log, "pause resource", err)
		return retry()
	}
	log.Warn("ozon answered 403 on a method this key never loaded: resource paused for this connection",
		slog.String("stage", "fetch"), slog.String("endpoint", apiErr.Endpoint), slog.Int("http_status", apiErr.Status), slog.Time("paused_until", until))
	return store.Outcome{Status: store.StatusFailedRetryable, Label: labelForbidden, ErrorClass: labelForbidden, HTTPStatus: apiErr.Status, KeepCursor: true, NextRunAt: until}
}

// retryable — failed_retryable с backoff base·2^(n−1) ≤ max и jitter.
func (e *Executor) retryable(task store.Task, class string, now time.Time, keepCursor bool) store.Outcome {
	delay := e.cfg.BackoffBase
	for i := 1; i < task.Attempts && delay < e.cfg.BackoffMax; i++ {
		delay *= 2
	}
	delay = min(delay, e.cfg.BackoffMax)
	delay += e.jitter(delay / 4)
	return store.Outcome{Status: store.StatusFailedRetryable, Label: class, ErrorClass: class, KeepCursor: keepCursor, NextRunAt: now.Add(delay)}
}

func (e *Executor) sideEffect(log *slog.Logger, what string, err error) {
	if err != nil {
		log.Warn("fetch task side effect failed", slog.String("stage", "fetch"), slog.String("action", what), slog.String("error", err.Error()))
	}
}
