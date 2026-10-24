package executor

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"sort"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

// emptyAccrualPostingsPage — служебная страница дня без отправлений: день
// загружен, запроса к Ozon нет.
const emptyAccrualPostingsPage = `{"posting_accruals":[]}`

var (
	// errOrdersNotLoaded — заказы дня ещё не загружены (not_ready).
	errOrdersNotLoaded = errors.New("executor: orders of the day are not loaded yet")
	// errOrdersRawInvalid — сохранённая страница заказов повреждена, пропала или не разбирается.
	errOrdersRawInvalid = errors.New("executor: stored orders page is not readable")
	// errOrdersRevised — заказы дня перечитаны между попытками: курсор
	// относится к другому списку номеров, день начинается заново.
	errOrdersRevised = errors.New("executor: orders of the day changed since the saved chunk")
)

// accrualPostings — начисления по отправлениям дня: номера FBO+FBS из
// текущей ревизии заказов того же дня, без дублей по возрастанию, порции по
// ozon.MaxAccrualPostingNumbers. Курсор — последний номер сохранённой порции:
// продолжение после сбоя идёт со следующего номера.
func (r *taskRun) accrualPostings(ctx context.Context, creds ozon.Credentials, cabinet connections.Cabinet) error {
	if r.cursor == "" && len(r.hashes) > 0 {
		// Все порции уже сохранены прежней попыткой.
		return nil
	}
	numbers, err := r.orderPostingNumbers(ctx, cabinet)
	if err != nil {
		return err
	}
	fp := numbersFingerprint(numbers)

	start := 0
	if r.cursor != "" {
		// Курсор — «отпечаток списка:последний номер»: продолжение только по
		// тому же списку, иначе новый номер меньше курсора был бы пропущен.
		cursorFP, last, ok := strings.Cut(r.cursor, ":")
		if !ok || cursorFP != fp {
			return errOrdersRevised
		}
		start = sort.SearchStrings(numbers, last)
		if start < len(numbers) && numbers[start] == last {
			start++
		}
	}
	if len(numbers) == 0 {
		return r.store(ctx, ozon.Page{No: r.nextPage, Body: []byte(emptyAccrualPostingsPage), ContentType: "application/json"})
	}
	for i := start; i < len(numbers); i += ozon.MaxAccrualPostingNumbers {
		chunk := numbers[i:min(i+ozon.MaxAccrualPostingNumbers, len(numbers))]
		page, err := r.exec.ozon.FetchAccrualPostings(ctx, r.task.Account, creds, chunk)
		if err != nil {
			return err
		}
		page.No = r.nextPage
		if i+len(chunk) < len(numbers) {
			page.Cursor = fp + ":" + chunk[len(chunk)-1]
		}
		if err := r.store(ctx, page); err != nil {
			return err
		}
	}
	return nil
}

// numbersFingerprint — отпечаток упорядоченного списка номеров дня.
func numbersFingerprint(numbers []string) string {
	sum := sha256.Sum256([]byte(strings.Join(numbers, "\n")))
	return hex.EncodeToString(sum[:8])
}

// orderPostingNumbers — номера отправлений дня из страниц заказов кабинета.
func (r *taskRun) orderPostingNumbers(ctx context.Context, cabinet connections.Cabinet) ([]string, error) {
	e := r.exec
	set := map[string]bool{}
	sources := 0
	for _, resource := range []string{ResourceOrdersFBO, ResourceOrdersFBS} {
		if !cabinet.Supports(resource) {
			continue
		}
		sources++
		key := store.TaskKey{Account: r.task.Account, ResourceType: resource, PeriodKey: r.task.PeriodKey}
		content, err := e.tasks.ContentPages(ctx, key)
		if errors.Is(err, store.ErrNoContent) {
			// Заказы дня загружены до появления списка страниц ревизии
			// (миграция 00004) — перезагрузить, иначе ждать пришлось бы вечно.
			reloaded, reloadErr := e.tasks.ReloadWithoutContent(ctx, key, e.now())
			if reloadErr != nil {
				return nil, &storeError{reloadErr}
			}
			if reloaded {
				r.log.Info("orders of the day requeued: loaded before content_pages", slog.String("stage", "fetch"), slog.String("orders_resource", resource))
			}
			return nil, fmt.Errorf("%w: %s", errOrdersNotLoaded, resource)
		}
		if errors.Is(err, store.ErrMissingObject) {
			return nil, fmt.Errorf("%w: %v", errOrdersRawInvalid, err)
		}
		if err != nil {
			return nil, &storeError{err}
		}
		// Ревизия заказов должна быть загружена целиком после конца дня — с
		// первой страницы, не дочитана по страницам до полуночи: иначе в ней нет
		// отправлений после последней загрузки «сегодня».
		dayStart, err := time.ParseInLocation(time.DateOnly, r.task.PeriodKey, e.cfg.Location)
		if err != nil {
			return nil, fmt.Errorf("%w: %q", errBadPeriod, r.task.PeriodKey)
		}
		if content.StartedAt.Before(dayStart.AddDate(0, 0, 1)) {
			return nil, fmt.Errorf("%w: %s revision started before the day ended", errOrdersNotLoaded, resource)
		}
		err = r.readContent(ctx, content, resource, errOrdersRawInvalid, func(body []byte) error {
			found, err := postingNumbersOf(body)
			if err != nil {
				return err
			}
			for _, n := range found {
				set[n] = true
			}
			return nil
		})
		if err != nil {
			return nil, err
		}
	}
	if sources == 0 {
		// Заказы с кабинета сняты — начислениям не из чего брать номера.
		return nil, errResourceNotEnabled
	}
	out := make([]string, 0, len(set))
	for n := range set {
		out = append(out, n)
	}
	sort.Strings(out)
	return out, nil
}

// postingNumbersOf — posting_number страницы списка отправлений (верхний
// уровень или result, как у клиента).
func postingNumbersOf(body []byte) ([]string, error) {
	type posting struct {
		PostingNumber string `json:"posting_number"`
	}
	var shape struct {
		Postings *[]posting `json:"postings"`
		Result   *struct {
			Postings *[]posting `json:"postings"`
		} `json:"result"`
	}
	if err := json.Unmarshal(body, &shape); err != nil {
		return nil, err
	}
	list := shape.Postings
	if list == nil && shape.Result != nil {
		list = shape.Result.Postings
	}
	if list == nil {
		return nil, errors.New(`no "postings" array`)
	}
	out := make([]string, 0, len(*list))
	for _, p := range *list {
		n := strings.TrimSpace(p.PostingNumber)
		if n == "" {
			return nil, errors.New("posting without posting_number")
		}
		out = append(out, n)
	}
	return out, nil
}
