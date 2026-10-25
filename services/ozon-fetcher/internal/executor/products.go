package executor

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/rawstore"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

// emptyProductInfoPage — служебная страница пустого каталога: Ozon отвергает
// запрос без product_id, день при этом загружен.
const emptyProductInfoPage = `{"items":[]}`

// snapshotEarlyError — окно снимка ещё не началось: задача ждёт его начала.
type snapshotEarlyError struct{ at time.Time }

func (e *snapshotEarlyError) Error() string {
	return "executor: snapshot window starts at " + e.at.Format(time.RFC3339)
}

var (
	// errSnapshotMissed — снимок за прошедший день: сегодняшний каталог под
	// вчерашней датой подменил бы данные, в Ozon не ходим.
	errSnapshotMissed = errors.New("executor: snapshot day has passed")
	// errCatalogNotLoaded — список товаров дня ещё не загружен (not_ready).
	errCatalogNotLoaded = errors.New("executor: product list of the day is not loaded yet")
	// errCatalogRawInvalid — сохранённая страница списка повреждена, пропала или не разбирается.
	errCatalogRawInvalid = errors.New("executor: stored product list page is not readable")
	// errCatalogRevised — список дня перезагружен между попытками: курсор
	// относится к другому набору товаров, подробности — заново.
	errCatalogRevised = errors.New("executor: product list of the day changed since the saved chunk")
)

// withinSnapshotDay выполняет загрузку только в день снимка МСК: прошедший
// день — сразу errSnapshotMissed, а попытка, пересёкшая полночь (ожидание
// лимитера, медленные страницы), прерывается на границе суток — страницы
// нового дня под вчерашней датой не сохраняются. Граница — по часам
// исполнителя.
func (r *taskRun) withinSnapshotDay(ctx context.Context, load func(ctx context.Context) error) error {
	return r.withinSnapshot(ctx, 0, load)
}

// withinSnapshot — то же с началом окна снимка from после полуночи МСК
// (остатки — с 05:00): раньше начала — snapshotEarlyError, в Ozon не ходим.
func (r *taskRun) withinSnapshot(ctx context.Context, from time.Duration, load func(ctx context.Context) error) error {
	loc := r.exec.cfg.Location
	day, err := time.ParseInLocation(time.DateOnly, r.task.PeriodKey, loc)
	if err != nil {
		return fmt.Errorf("%w: %q", errBadPeriod, r.task.PeriodKey)
	}
	now := r.exec.now().In(loc)
	end := day.AddDate(0, 0, 1)
	if now.Before(day) || !now.Before(end) {
		return fmt.Errorf("%w: %s", errSnapshotMissed, r.task.PeriodKey)
	}
	if start := day.Add(from); now.Before(start) {
		return &snapshotEarlyError{at: start}
	}
	dayCtx, cancel := context.WithTimeoutCause(ctx, end.Sub(now), errSnapshotMissed)
	defer cancel()
	err = load(dayCtx)
	if err != nil && ctx.Err() == nil && errors.Is(context.Cause(dayCtx), errSnapshotMissed) {
		return fmt.Errorf("%w: %s ended during the load", errSnapshotMissed, r.task.PeriodKey)
	}
	return err
}

// productList — список всех товаров кабинета по last_id до пустой страницы.
func (r *taskRun) productList(ctx context.Context, creds ozon.Credentials) error {
	if r.cursor == "" && len(r.hashes) > 0 {
		// Все страницы уже сохранены прежней попыткой.
		return nil
	}
	return r.withinSnapshotDay(ctx, func(ctx context.Context) error {
		return r.exec.ozon.FetchProductList(ctx, r.task.Account, creds, r.cursor, r.nextPage, func(p ozon.Page) error {
			return r.store(ctx, p)
		})
	})
}

// productInfo — подробности по каждому product_id списка того же дня, без
// дублей по возрастанию, порции по ozon.MaxProductInfoIDs. Курсор —
// «отпечаток списка:последний id» сохранённой порции.
func (r *taskRun) productInfo(ctx context.Context, creds ozon.Credentials, cabinet connections.Cabinet) error {
	if r.cursor == "" && len(r.hashes) > 0 {
		return nil
	}
	return r.withinSnapshotDay(ctx, func(ctx context.Context) error { return r.loadProductInfo(ctx, creds, cabinet) })
}

func (r *taskRun) loadProductInfo(ctx context.Context, creds ozon.Credentials, cabinet connections.Cabinet) error {
	ids, err := r.catalogProductIDs(ctx, cabinet)
	if err != nil {
		return err
	}
	keys := make([]string, len(ids))
	for i, id := range ids {
		keys[i] = strconv.FormatInt(id, 10)
	}
	fp := numbersFingerprint(keys)

	start := 0
	if r.cursor != "" {
		cursorFP, last, ok := strings.Cut(r.cursor, ":")
		lastID, parseErr := strconv.ParseInt(last, 10, 64)
		if !ok || cursorFP != fp || parseErr != nil {
			return errCatalogRevised
		}
		start, _ = slices.BinarySearch(ids, lastID)
		if start < len(ids) && ids[start] == lastID {
			start++
		}
	}
	if len(ids) == 0 {
		return r.store(ctx, ozon.Page{No: r.nextPage, Body: []byte(emptyProductInfoPage), ContentType: "application/json"})
	}
	missing := 0
	for i := start; i < len(ids); i += ozon.MaxProductInfoIDs {
		chunk := ids[i:min(i+ozon.MaxProductInfoIDs, len(ids))]
		page, chunkMissing, err := r.exec.ozon.FetchProductInfo(ctx, r.task.Account, creds, chunk)
		if err != nil {
			return err
		}
		missing += chunkMissing
		page.No = r.nextPage
		if i+len(chunk) < len(ids) {
			page.Cursor = fp + ":" + keys[i+len(chunk)-1]
		}
		if err := r.store(ctx, page); err != nil {
			return err
		}
	}
	if missing > 0 {
		// Ozon молча пропускает удалённые между списком и подробностями товары.
		r.log.Warn("product info: products of the list missing in response", slog.String("stage", "fetch"),
			slog.Int("missing", missing), slog.Int("requested", len(ids)-start))
	}
	return nil
}

// catalogProductIDs — product_id текущей ревизии списка товаров того же дня.
func (r *taskRun) catalogProductIDs(ctx context.Context, cabinet connections.Cabinet) ([]int64, error) {
	e := r.exec
	if !cabinet.Supports(ResourceProductsList) {
		// Список снят с кабинета — подробностям не из чего брать товары.
		return nil, errResourceNotEnabled
	}
	key := store.TaskKey{Account: r.task.Account, ResourceType: ResourceProductsList, PeriodKey: r.task.PeriodKey}
	content, err := e.tasks.ContentPages(ctx, key)
	if errors.Is(err, store.ErrNoContent) {
		return nil, errCatalogNotLoaded
	}
	if errors.Is(err, store.ErrMissingObject) {
		return nil, fmt.Errorf("%w: %v", errCatalogRawInvalid, err)
	}
	if err != nil {
		return nil, &storeError{err}
	}
	set := map[int64]bool{}
	listed, total := 0, int64(-1)
	err = r.readContent(ctx, content, ResourceProductsList, errCatalogRawInvalid, func(body []byte) error {
		ids, pageTotal, err := ozon.ProductListIDs(body)
		if err != nil {
			return err
		}
		listed += len(ids)
		if len(ids) > 0 {
			// total — со страницы с товарами: что пишет концевая пустая, не подтверждено.
			total = pageTotal
		}
		for _, id := range ids {
			set[id] = true
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	// Число товаров снимка — для сверки с листингами Symfony (raw на проде не читается).
	r.log.Info("product list read", slog.String("stage", "fetch"), slog.Int("listed", listed), slog.Int("unique", len(set)), slog.Int64("total", total))
	if total >= 0 && int64(listed) != total {
		// Каталог менялся во время обхода; повтор зациклился бы у активного продавца.
		r.log.Warn("product list: item count differs from total", slog.String("stage", "fetch"),
			slog.Int("listed", listed), slog.Int64("total", total))
	}
	out := make([]int64, 0, len(set))
	for id := range set {
		out = append(out, id)
	}
	slices.Sort(out)
	return out, nil
}

// readContent читает страницы ревизии зависимости по порядку. Временный сбой
// S3 — storeError (повтор); пропавший, повреждённый или неразборчивый объект —
// invalid (постоянная ошибка: неизменяемый raw сам не исправится).
func (r *taskRun) readContent(ctx context.Context, content store.Content, resource string, invalid error, parse func(body []byte) error) error {
	e := r.exec
	if e.rawReader == nil {
		return errors.New("executor: raw reader is not configured")
	}
	if content.Key.Account != r.task.Account {
		return fmt.Errorf("%w: %s content of another connection", invalid, resource)
	}
	for _, p := range content.Pages {
		body, err := e.rawReader.Get(ctx, content.Key.Account, rawstore.Locator{
			ResourceType: content.Key.ResourceType, PeriodKey: content.Key.PeriodKey, SHA256: p.SHA256, ContentType: p.ContentType,
		})
		if err != nil {
			if ctx.Err() != nil {
				return ctx.Err()
			}
			if errors.Is(err, rawstore.ErrObjectInvalid) || errors.Is(err, rawstore.ErrObjectNotFound) {
				return fmt.Errorf("%w: %s page %d: %v", invalid, resource, p.PageNo, err)
			}
			return &storeError{err}
		}
		if err := parse(body); err != nil {
			return fmt.Errorf("%w: %s page %d: %v", invalid, resource, p.PageNo, err)
		}
	}
	return nil
}
