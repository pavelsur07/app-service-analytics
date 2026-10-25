// Package executor выполняет одну захваченную задачу загрузки: страницы Ozon →
// S3 → журнал, итог по таблице исходов (docs/tasks/ozon-fetcher-ingestion/stages/stage-9.md).
package executor

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"log/slog"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/rawstore"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

// Ресурсы первой волны (contract marketplace-connections v1).
const (
	ResourceAccrualByDay     = "ozon.finance.accrual-by-day"
	ResourceAccrualTypes     = "ozon.finance.accrual-types"
	ResourceRealization      = "ozon.finance.realization"
	ResourceMutualSettlement = "ozon.finance.mutual-settlement"

	// Отправления по дате создания; period_key — день МСК. Текущий статус —
	// в каждом отправлении, смены видны ревизиями перечитанных дней.
	ResourceOrdersFBO = "ozon.orders.fbo"
	ResourceOrdersFBS = "ozon.orders.fbs"

	// Начисления по отправлениям дня (period_key — день МСК создания
	// отправлений): номера — из загруженных страниц заказов того же дня.
	ResourceAccrualPostings = "ozon.finance.accrual-postings"

	// Каталог товаров — снимок дня (period_key — день МСК загрузки): список
	// всех товаров и подробности по product_id из списка того же дня.
	ResourceProductsList = "ozon.products.list"
	ResourceProductsInfo = "ozon.products.info"

	// Остатки — снимок дня (period_key — день МСК снимка) с 05:00; конец
	// недели — снимок понедельника («неделя готова» в понедельник 06:00).
	ResourceStocks = "ozon.stocks"

	// PeriodRef — period_key справочников.
	PeriodRef = "ref"
)

// postingResources — схема списка отправлений ресурса.
var postingResources = map[string]ozon.PostingScheme{
	ResourceOrdersFBO: ozon.SchemeFBO,
	ResourceOrdersFBS: ozon.SchemeFBS,
}

// gatedResources — ресурсы, которые Symfony выдаёт кабинетам по списку
// компаний: снятый с кабинета ресурс не грузится и по поставленным задачам.
var gatedResources = map[string]bool{
	ResourceOrdersFBO: true, ResourceOrdersFBS: true, ResourceAccrualPostings: true,
	ResourceProductsList: true, ResourceProductsInfo: true,
	ResourceStocks: true,
}

// IsPostingResource — ресурс списков отправлений.
func IsPostingResource(resourceType string) bool {
	_, ok := postingResources[resourceType]
	return ok
}

// TaskStore — часть store.Tasks, нужная исполнителю.
type TaskStore interface {
	ExtendLease(ctx context.Context, key store.TaskKey, attemptID string, until time.Time) error
	RecordPage(ctx context.Context, key store.TaskKey, attemptID string, obj store.RawObject, nextCursor string) error
	FindObject(ctx context.Context, key store.TaskKey, sha256 string) (store.RawObject, bool, error)
	FinishTask(ctx context.Context, key store.TaskKey, attemptID string, out store.Outcome, now time.Time) (int, error)
	RecordAuthFailure(ctx context.Context, ref account.Ref, now time.Time, pauseFor func(failures int) time.Duration) (int, error)
	ResumeConnection(ctx context.Context, ref account.Ref) error
	StopResourceAllConnections(ctx context.Context, resourceType, reason string) error
	SucceededSince(ctx context.Context, ref account.Ref, resourceType string, since time.Time) (bool, error)
	HaltAllConnections(ctx context.Context, ref account.Ref, resourceType, reason string) (bool, error)
	PauseResource(ctx context.Context, ref account.Ref, resourceType string, until time.Time, reason string) error
	ContentPages(ctx context.Context, key store.TaskKey) (store.Content, error)
	ReloadWithoutContent(ctx context.Context, key store.TaskKey, now time.Time) (bool, error)
	SaveCursor(ctx context.Context, key store.TaskKey, attemptID, cursor string) error
	AdsReportInFlight(ctx context.Context, except store.TaskKey) (bool, error)
}

// RawReader — чтение сохранённой страницы (зависимые ресурсы).
type RawReader interface {
	Get(ctx context.Context, ref account.Ref, loc rawstore.Locator) ([]byte, error)
}

// RawStore — запись страницы в S3.
type RawStore interface {
	Put(ctx context.Context, ref account.Ref, obj rawstore.Object) (rawstore.Stored, error)
}

// Ozon — загрузки ресурсов первой волны.
type Ozon interface {
	FetchAccrualByDay(ctx context.Context, ref account.Ref, creds ozon.Credentials, day, fromCursor string, firstPageNo int, onPage ozon.PageHandler) error
	FetchAccrualTypes(ctx context.Context, ref account.Ref, creds ozon.Credentials) (ozon.Page, error)
	FetchRealization(ctx context.Context, ref account.Ref, creds ozon.Credentials, year, month int) (ozon.Page, error)
	FetchMutualSettlement(ctx context.Context, ref account.Ref, creds ozon.Credentials, year, month int, poll ozon.ReportPoll, sleep func(context.Context, time.Duration) error) (ozon.Page, error)
	FetchPostingList(ctx context.Context, ref account.Ref, creds ozon.Credentials, scheme ozon.PostingScheme, w ozon.PostingWindow, fromCursor string, firstPageNo int, onPage ozon.PageHandler) error
	FetchAccrualPostings(ctx context.Context, ref account.Ref, creds ozon.Credentials, numbers []string) (ozon.Page, error)
	FetchProductList(ctx context.Context, ref account.Ref, creds ozon.Credentials, fromLastID string, firstPageNo int, onPage ozon.PageHandler) error
	FetchProductInfo(ctx context.Context, ref account.Ref, creds ozon.Credentials, ids []int64) (ozon.Page, int, error)
	FetchStocks(ctx context.Context, ref account.Ref, creds ozon.Credentials, fromCursor string, firstPageNo int, onPage ozon.PageHandler) error
}

// Refresher — обновление снимка кабинетов после 401.
type Refresher interface {
	ForceRefresh(ctx context.Context) (bool, error)
}

// Config — тайминги исполнителя.
type Config struct {
	Lease          time.Duration // срок lease; продление — раз в треть
	FinishTimeout  time.Duration // запись итога после отмены
	BackoffBase    time.Duration // transient: base·2^(n−1)
	BackoffMax     time.Duration
	RateLimitPause time.Duration // 429 без Retry-After
	AuthRetryPause time.Duration // пауза кабинета после первого auth: снимок с новым ключом успевает обновиться
	AuthSuspend    time.Duration // пауза кабинета на втором auth подряд
	// ForbiddenPause — пауза ресурса подключения на 403 по методу, который
	// у ключа ещё не отвечал 200 (ADR-043 п. 5): у ключа нет роли, и частый
	// повтор — те самые ошибочные запросы, за которые Ozon ограничивает доступ.
	ForbiddenPause  time.Duration
	NoCredentialsIn time.Duration // повтор задачи без ключа seller
	NotReadyDelay   map[string]time.Duration
	ReportPoll      ozon.ReportPoll
	Location        *time.Location // границы дня отправлений (Europe/Moscow)

	// Отчёты рекламы (ozon-7-ads-reports.md): окно заказа [From; To) после
	// полуночи МСК, опрос, длина попытки, срок до отказа от отчёта, повтор
	// после ошибки Ozon, ожидание чужого отчёта аккаунта, ожидание кампаний дня.
	AdsReportWindowFrom time.Duration
	AdsReportWindowTo   time.Duration
	AdsReportPoll       time.Duration
	AdsReportAttempt    time.Duration
	AdsReportAbandon    time.Duration
	AdsReportRetry      time.Duration
	AdsReportBusy       time.Duration
	// AdsReportReservation — срок резерва заказа без номера отчёта (исход заказа неизвестен).
	AdsReportReservation time.Duration
	AdsCampaignsWait     time.Duration
}

// DefaultConfig — значения плана (stage-9.md).
func DefaultConfig() Config {
	return Config{
		Lease:                2 * time.Minute,
		FinishTimeout:        10 * time.Second,
		BackoffBase:          time.Minute,
		BackoffMax:           time.Hour,
		RateLimitPause:       30 * time.Second,
		AuthRetryPause:       time.Minute,
		AuthSuspend:          time.Hour,
		ForbiddenPause:       24 * time.Hour,
		NoCredentialsIn:      time.Hour,
		AdsReportWindowFrom:  30 * time.Minute,
		AdsReportWindowTo:    4 * time.Hour,
		AdsReportPoll:        30 * time.Second,
		AdsReportAttempt:     20 * time.Minute,
		AdsReportAbandon:     3 * time.Hour,
		AdsReportRetry:       30 * time.Minute,
		AdsReportBusy:        5 * time.Minute,
		AdsReportReservation: time.Hour,
		AdsCampaignsWait:     30 * time.Minute,
		NotReadyDelay: map[string]time.Duration{
			ResourceAccrualByDay:     time.Hour,
			ResourceAccrualTypes:     time.Hour,
			ResourceRealization:      24 * time.Hour,
			ResourceMutualSettlement: 24 * time.Hour,
			ResourceAccrualPostings:  time.Hour,        // заказы дня ещё не загружены
			ResourceProductsInfo:     10 * time.Minute, // список дня грузится за секунды
		},
		ReportPoll: ozon.DefaultReportPoll,
		// Москва без перехода на летнее время с 2014 г.; приложение передаёт
		// загруженную Europe/Moscow.
		Location: time.FixedZone("MSK", 3*60*60),
	}
}

// Executor — без состояния задачи; безопасен для параллельных Run.
type Executor struct {
	cfg       Config
	tasks     TaskStore
	raw       RawStore
	rawReader RawReader
	ozon      Ozon
	perf      Performance
	refresher Refresher
	logger    *slog.Logger
	now       func() time.Time
	jitter    func(time.Duration) time.Duration // [0; d)
	sleep     func(context.Context, time.Duration) error
}

func New(cfg Config, tasks TaskStore, raw RawStore, client Ozon, refresher Refresher, logger *slog.Logger,
	now func() time.Time, jitter func(time.Duration) time.Duration, sleep func(context.Context, time.Duration) error) *Executor {
	return &Executor{cfg: cfg, tasks: tasks, raw: raw, ozon: client, refresher: refresher, logger: logger, now: now, jitter: jitter, sleep: sleep}
}

// WithRawReader — копия с чтением сохранённых страниц (начисления по
// отправлениям, подробности товаров).
func (e *Executor) WithRawReader(r RawReader) *Executor {
	clone := *e
	clone.rawReader = r
	return &clone
}

// errLeaseLost — внутренний сигнал: продление не удалось, задачу ведёт другой воркер.
var errLeaseLost = errors.New("executor: lease lost")

// Result — итог для диспетчера и логов.
type Result struct {
	Outcome  string
	Pages    int
	Revision int
}

// Run выполняет задачу. traceID — сквозной идентификатор для логов.
func (e *Executor) Run(ctx context.Context, task store.Task, cabinet connections.Cabinet, traceID string) Result {
	started := e.now()
	log := e.logger.With(
		slog.String("job_id", task.AttemptID), slog.String("trace_id", traceID),
		slog.String("company_id", task.Account.CompanyID), slog.String("marketplace_account_id", task.Account.AccountID), slog.String("resource_type", task.ResourceType),
		slog.String("period_key", task.PeriodKey), slog.Int("retry_count", task.Attempts-1),
	)
	log.Info("fetch task started", slog.String("stage", "fetch"))

	taskCtx, cancel := context.WithCancelCause(ctx)
	defer cancel(nil)
	stopLease := e.keepLease(taskCtx, cancel, task, log)

	run := &taskRun{exec: e, task: task, cabinet: cabinet, log: log, hashes: append([]string(nil), task.PageHashes...), cursor: task.Cursor, nextPage: task.NextPageNo}
	fetchErr := run.fetch(taskCtx, cabinet)

	// Lease продлевается и во время побочных действий исхода (пауза кабинета,
	// стоп ресурса); у них свой ограниченный контекст.
	out := e.outcome(ctx, taskCtx, task, run, fetchErr, log)
	stopLease()
	result := Result{Outcome: out.Label, Pages: run.pages}

	if !errors.Is(context.Cause(taskCtx), errLeaseLost) {
		finishCtx, finishCancel := context.WithTimeout(context.WithoutCancel(ctx), e.cfg.FinishTimeout)
		revision, err := e.tasks.FinishTask(finishCtx, task.TaskKey, task.AttemptID, out, e.now())
		finishCancel()
		switch {
		case errors.Is(err, store.ErrLeaseLost):
			result.Outcome = "lease_lost"
		case err != nil:
			log.Error("fetch task finish failed", slog.String("stage", "fetch"), slog.String("error", err.Error()))
			result.Outcome = "finish_failed"
		default:
			result.Revision = revision
			if out.Label == "done" && task.ContentHash != "" && revision > task.Revision {
				log.Info("source revised", slog.String("stage", "fetch"), slog.Int("revision", revision))
			}
		}
	} else {
		result.Outcome = "lease_lost"
	}

	level := slog.LevelInfo
	switch {
	case out.Status == store.StatusFailedRetryable, out.Label == labelSnapshotMissed:
		// Пропущенный снимок — следствие простоя, о котором уже сигналят;
		// пропуск виден в покрытии.
		level = slog.LevelWarn
	case out.Status == store.StatusFailedPermanent:
		level = slog.LevelError
	case out.Label == labelReportPending, out.Label == labelReportWait, out.Label == labelReportBusy:
		// Ожидание отчёта рекламы — штатно.
		level = slog.LevelInfo
	}
	if result.Outcome == "lease_lost" {
		level = slog.LevelWarn
	}
	attrs := []any{slog.String("stage", "fetch"), slog.String("outcome", result.Outcome), slog.String("error_class", out.ErrorClass),
		slog.Int("pages", run.pages), slog.Int("pages_reused", run.reused), slog.Int64("duration_ms", e.now().Sub(started).Milliseconds())}
	if fetchErr != nil {
		attrs = append(attrs, slog.String("error", fetchErr.Error()))
	}
	log.Log(context.WithoutCancel(ctx), level, "fetch task finished", attrs...)
	return result
}

// keepLease продлевает lease раз в треть срока; потеря — отмена задачи.
// У продления свой контекст: остановка прерывает и зависший вызов к БД,
// каждый вызов ограничен третью срока lease.
func (e *Executor) keepLease(ctx context.Context, cancel context.CancelCauseFunc, task store.Task, log *slog.Logger) func() {
	renewCtx, stopRenew := context.WithCancel(ctx)
	stopped := make(chan struct{})
	go func() {
		defer close(stopped)
		interval := e.cfg.Lease / 3
		for {
			select {
			case <-renewCtx.Done():
				return
			case <-time.After(interval):
			}
			callCtx, callCancel := context.WithTimeout(renewCtx, interval)
			err := e.tasks.ExtendLease(callCtx, task.TaskKey, task.AttemptID, e.now().Add(e.cfg.Lease))
			callCancel()
			switch {
			case errors.Is(err, store.ErrLeaseLost):
				log.Warn("fetch task lease lost", slog.String("stage", "fetch"))
				cancel(errLeaseLost)
				return
			case err != nil && renewCtx.Err() == nil:
				// Сбой БД: lease ещё действует, пробуем на следующем тике.
				log.Warn("fetch task lease extension failed", slog.String("stage", "fetch"), slog.String("error", err.Error()))
			}
		}
	}()
	return func() {
		stopRenew()
		<-stopped
	}
}

// taskRun — состояние одной попытки.
type taskRun struct {
	exec     *Executor
	task     store.Task
	cabinet  connections.Cabinet
	log      *slog.Logger
	hashes   []string
	cursor   string
	nextPage int
	pages    int
	reused   int // страниц без записи в S3: то же содержимое уже сохранено
}

// errNoCredentials — у кабинета нет ключа seller для финансового ресурса.
var errNoCredentials = errors.New("executor: no seller credentials")

// errResourceNotEnabled — ресурс снят с кабинета в снимке Symfony.
var errResourceNotEnabled = errors.New("executor: resource is not enabled for cabinet")

func (r *taskRun) fetch(ctx context.Context, cabinet connections.Cabinet) error {
	// Задача и ключи соединяются только здесь: ключи другого подключения
	// задачу не выполняют (services/AGENTS.md §4).
	if cabinet.Account != r.task.Account {
		return fmt.Errorf("%w: cabinet of another connection", errNoCredentials)
	}
	if IsAdsResource(r.task.ResourceType) {
		return r.fetchAds(ctx, cabinet)
	}
	if cabinet.Seller == nil {
		return errNoCredentials
	}
	creds := *cabinet.Seller
	ref := r.task.Account
	e := r.exec

	switch r.task.ResourceType {
	case ResourceAccrualByDay:
		if r.cursor == "" && len(r.hashes) > 0 {
			// Все страницы дня уже сохранены прежней попыткой (упала запись
			// итога): загружать день заново — удвоить страницы и хеш.
			return nil
		}
		return e.ozon.FetchAccrualByDay(ctx, ref, creds, r.task.PeriodKey, r.cursor, r.nextPage, func(p ozon.Page) error {
			return r.store(ctx, p)
		})
	case ResourceAccrualTypes:
		return r.single(ctx, func() (ozon.Page, error) { return e.ozon.FetchAccrualTypes(ctx, ref, creds) })
	case ResourceRealization, ResourceMutualSettlement:
		year, month, err := parseMonth(r.task.PeriodKey)
		if err != nil {
			return err
		}
		if r.task.ResourceType == ResourceRealization {
			return r.single(ctx, func() (ozon.Page, error) { return e.ozon.FetchRealization(ctx, ref, creds, year, month) })
		}
		return r.single(ctx, func() (ozon.Page, error) {
			return e.ozon.FetchMutualSettlement(ctx, ref, creds, year, month, e.cfg.ReportPoll, e.sleep)
		})
	default:
		// Отправления, начисления по ним и каталог включаются по кабинетам (снимок
		// Symfony): снятый с кабинета ресурс не грузится и по уже поставленным задачам.
		if gatedResources[r.task.ResourceType] && !cabinet.Supports(r.task.ResourceType) {
			return errResourceNotEnabled
		}
		if scheme, ok := postingResources[r.task.ResourceType]; ok {
			return r.postings(ctx, creds, scheme)
		}
		switch r.task.ResourceType {
		case ResourceAccrualPostings:
			return r.accrualPostings(ctx, creds, cabinet)
		case ResourceProductsList:
			return r.productList(ctx, creds)
		case ResourceProductsInfo:
			return r.productInfo(ctx, creds, cabinet)
		case ResourceStocks:
			return r.stocks(ctx, creds)
		}
		return fmt.Errorf("%w: %s", errUnknownResource, r.task.ResourceType)
	}
}

// postings — отправления дня МСК [00:00; 00:00 следующего дня) постранично.
func (r *taskRun) postings(ctx context.Context, creds ozon.Credentials, scheme ozon.PostingScheme) error {
	if r.cursor == "" && len(r.hashes) > 0 {
		// Все страницы окна уже сохранены прежней попыткой.
		return nil
	}
	from, err := time.ParseInLocation(time.DateOnly, r.task.PeriodKey, r.exec.cfg.Location)
	if err != nil {
		return fmt.Errorf("%w: %q", errBadPeriod, r.task.PeriodKey)
	}
	w := ozon.PostingWindow{From: from, To: from.AddDate(0, 0, 1)}
	return r.exec.ozon.FetchPostingList(ctx, r.task.Account, creds, scheme, w, r.cursor, r.nextPage, func(p ozon.Page) error {
		return r.store(ctx, p)
	})
}

var (
	errUnknownResource = errors.New("executor: unknown resource type")
	errBadPeriod       = errors.New("executor: bad period key")
)

// single — ресурс одной страницей. Страница, сохранённая прежней попыткой
// (упала запись итога или процесс), не загружается повторно.
func (r *taskRun) single(ctx context.Context, fetch func() (ozon.Page, error)) error {
	if len(r.hashes) > 0 {
		return nil
	}
	r.cursor, r.nextPage = "", 1
	page, err := fetch()
	if err != nil {
		return err
	}
	page.No = 1
	return r.store(ctx, page)
}

// storeError — сбой S3 или журнала при сохранении страницы.
type storeError struct{ err error }

func (e *storeError) Error() string { return "executor: store page: " + e.err.Error() }
func (e *storeError) Unwrap() error { return e.err }

// store: S3 → журнал (страница, курсор, хеш) — до запроса следующей страницы.
// Страница с тем же содержимым, уже сохранённая для этой задачи, в S3 не
// пишется: журнал попытки ссылается на существующий объект.
func (r *taskRun) store(ctx context.Context, p ozon.Page) error {
	e := r.exec
	obj := store.RawObject{PageNo: p.No, SHA256: sha256Hex(p.Body), SizeBytes: int64(len(p.Body)), ContentType: p.ContentType}

	existing, found, err := e.tasks.FindObject(ctx, r.task.TaskKey, obj.SHA256)
	if err != nil {
		return &storeError{err}
	}
	if found {
		// content_type — того объекта, что записан: при чтении из него
		// пересобирается расширение ключа (ADR-044).
		obj.StorageURI, obj.StoredBytes, obj.ContentType = existing.StorageURI, existing.StoredBytes, existing.ContentType
		r.reused++
	} else {
		stored, err := e.raw.Put(ctx, r.task.Account, rawstore.Object{
			ResourceType: r.task.ResourceType, PeriodKey: r.task.PeriodKey, ContentType: p.ContentType, Body: p.Body,
		})
		if err != nil {
			return &storeError{err}
		}
		obj.StorageURI, obj.StoredBytes = stored.URI, stored.StoredBytes
	}
	err = e.tasks.RecordPage(ctx, r.task.TaskKey, r.task.AttemptID, obj, p.Cursor)
	if errors.Is(err, store.ErrLeaseLost) {
		return err
	}
	if err != nil {
		return &storeError{err}
	}
	r.hashes = append(r.hashes, obj.SHA256)
	r.cursor, r.nextPage = p.Cursor, p.No+1
	r.pages++
	return nil
}

// contentHash — sha256 упорядоченного списка хешей страниц загрузки.
func contentHash(hashes []string) string {
	sum := sha256.Sum256([]byte(strings.Join(hashes, "\n")))
	return hex.EncodeToString(sum[:])
}

func sha256Hex(b []byte) string {
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:])
}

func parseMonth(periodKey string) (int, int, error) {
	t, err := time.Parse("2006-01", periodKey)
	if err != nil {
		return 0, 0, fmt.Errorf("%w: %q", errBadPeriod, periodKey)
	}
	return t.Year(), int(t.Month()), nil
}
