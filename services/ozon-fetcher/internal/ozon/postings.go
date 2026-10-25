package ozon

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
)

// Списки отправлений — замена снятых /v2/posting/fbo/list и /v3/posting/fbs/list
// (docs/tasks/ozon-orders-posting-list/problem.md §4).
const (
	EndpointPostingFBOList = "/v3/posting/fbo/list"
	EndpointPostingFBSList = "/v4/posting/fbs/list"
)

// PostingPageLimit — максимум Ozon для новых методов: больше — 400.
const PostingPageLimit = 100

// MaxPostingPages — защитный предел страниц одного окна (100 000 отправлений
// за день — с запасом на распродажи; от зацикливания защищает проверка
// повтора курсора). Достижение предела с признаком продолжения — ошибка, не
// обрезка окна.
const MaxPostingPages = 1000

// PostingScheme — схема работы: FBO (склад Ozon) или FBS (склад продавца).
type PostingScheme string

const (
	SchemeFBO PostingScheme = "fbo"
	SchemeFBS PostingScheme = "fbs"
)

// PostingWindow — окно по дате создания [From; To). Фильтр по дате смены
// статуса (filter.last_changed_status_date) FBO v3 молча игнорирует (живой
// ответ 21.10.2026) — смены статусов видны перечитыванием окон создания.
type PostingWindow struct {
	From time.Time
	To   time.Time
}

func postingEndpoint(scheme PostingScheme) (string, error) {
	switch scheme {
	case SchemeFBO:
		return EndpointPostingFBOList, nil
	case SchemeFBS:
		return EndpointPostingFBSList, nil
	default:
		return "", fmt.Errorf("ozon: unknown posting scheme %q", scheme)
	}
}

// postingRequest — тело запроса. offset и dir не отправляются: новые методы
// молча игнорируют их и отдают первые 100 отправлений окна. FBO — с
// translit false, как у PHP (ADR-042): v3 флаг выполняет, и с true кластеры
// и тип оплаты пришли бы латиницей, не так, как в загрузке PHP.
func postingRequest(scheme PostingScheme, w PostingWindow, cursor string) map[string]any {
	filter := map[string]any{"since": w.From.UTC().Format(time.RFC3339), "to": w.To.UTC().Format(time.RFC3339)}
	req := map[string]any{
		"filter":   filter,
		"limit":    PostingPageLimit,
		"sort_dir": "ASC",
		// Без персональных данных покупателя: только аналитика и финансы.
		"with": map[string]bool{"analytics_data": true, "financial_data": true},
	}
	switch {
	case scheme == SchemeFBO:
		// Курсор — и на первой странице (пустой строкой), как у PHP.
		req["translit"] = false
		req["cursor"] = cursor
	case cursor != "":
		req["cursor"] = cursor
	}
	return req
}

// postingShape — признаки страницы. Живой ответ 21.10.2026 (FBO v3, FBS v4):
// postings/has_next/cursor на верхнем уровне; result — на случай смены формы.
type postingShape struct {
	Postings json.RawMessage `json:"postings"`
	HasNext  *bool           `json:"has_next"`
	Cursor   *string         `json:"cursor"`
	Result   *struct {
		Postings json.RawMessage `json:"postings"`
		HasNext  *bool           `json:"has_next"`
		Cursor   *string         `json:"cursor"`
	} `json:"result"`
}

// parsePostingPage — число отправлений, курсор следующей страницы и признак
// продолжения.
func parsePostingPage(endpoint string, body []byte) (int, string, bool, error) {
	var shape postingShape
	if err := json.Unmarshal(body, &shape); err != nil {
		return 0, "", false, invalidResponse(endpoint, "response is not a JSON object")
	}
	postings, hasNext, cursor := shape.Postings, shape.HasNext, shape.Cursor
	if !isJSONArray(postings) && shape.Result != nil {
		postings, hasNext, cursor = shape.Result.Postings, shape.Result.HasNext, shape.Result.Cursor
	}
	if !isJSONArray(postings) {
		return 0, "", false, invalidResponse(endpoint, `response has no "postings" array`)
	}
	var items []json.RawMessage
	_ = json.Unmarshal(postings, &items)

	next := ""
	if cursor != nil {
		next = strings.TrimSpace(*cursor)
	}
	switch {
	case hasNext != nil && *hasNext:
		if next == "" {
			return 0, "", false, invalidResponse(endpoint, "has_next is true but cursor is empty")
		}
		return len(items), next, true, nil
	case hasNext != nil:
		return len(items), "", false, nil
	case next != "" && len(items) > 0:
		// Без has_next продолжение определяет курсор.
		return len(items), next, true, nil
	case len(items) >= PostingPageLimit:
		// Полная страница без признаков продолжения: ровно та тихая обрезка,
		// которой отличается смена URL без смены пагинации.
		return 0, "", false, invalidResponse(endpoint, "full page without has_next or cursor")
	default:
		return len(items), "", false, nil
	}
}

// FetchPostingList выгружает отправления окна постранично, начиная с
// fromCursor ("" — с начала). Номер первой страницы — firstPageNo. Каждая
// страница отдаётся в onPage до запроса следующей; Page.Cursor — курсор
// следующей страницы, "" у последней.
func (c *Client) FetchPostingList(ctx context.Context, ref account.Ref, creds Credentials, scheme PostingScheme, w PostingWindow, fromCursor string, firstPageNo int, onPage PageHandler) error {
	endpoint, err := postingEndpoint(scheme)
	if err != nil {
		return err
	}
	if !w.From.Before(w.To) {
		return fmt.Errorf("ozon: empty posting window %s..%s", w.From.Format(time.RFC3339), w.To.Format(time.RFC3339))
	}

	cursor := strings.TrimSpace(fromCursor)
	seen := map[string]bool{}
	if cursor != "" {
		seen[cursor] = true
	}

	for no := firstPageNo; ; no++ {
		// Предел — по номеру страницы окна, не по попытке: продолжение после
		// рестарта не получает ещё MaxPostingPages страниц.
		if no > MaxPostingPages {
			return invalidResponse(endpoint, fmt.Sprintf("more than %d pages with a continuation marker", MaxPostingPages))
		}
		resp, err := c.Post(ctx, ref, creds, endpoint, postingRequest(scheme, w, cursor))
		if err != nil {
			return err
		}
		_, next, more, err := parsePostingPage(endpoint, resp.Body)
		if err != nil {
			return err
		}
		if more && seen[next] {
			return invalidResponse(endpoint, fmt.Sprintf("repeated pagination cursor on page %d", no))
		}
		seen[next] = true

		if err := onPage(Page{No: no, Body: resp.Body, Cursor: next, ContentType: "application/json"}); err != nil {
			return err
		}
		if !more {
			return nil
		}
		cursor = next
	}
}
