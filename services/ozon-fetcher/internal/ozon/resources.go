package ozon

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
)

// Endpoints ресурсов первой волны (docs/ozon-api.md).
const (
	EndpointAccrualByDay     = "/v1/finance/accrual/by-day"
	EndpointAccrualTypes     = "/v1/finance/accrual/types"
	EndpointRealization      = "/v2/finance/realization"
	EndpointMutualSettlement = "/v1/finance/mutual-settlement"
	EndpointReportInfo       = "/v1/report/info"
)

// MaxAccrualPages — защитный предел страниц дня. На проде день — одна
// страница; достижение предела с непустым курсором — ошибка, не обрезка дня.
const MaxAccrualPages = 100

// Page — страница ответа: исходные байты без перекодирования.
type Page struct {
	No          int    // с 1
	Body        []byte // как прислал Ozon
	Cursor      string // курсор следующей страницы; "" — последняя
	ContentType string
}

// PageHandler сохраняет страницу до запроса следующей. Ошибка останавливает обход.
type PageHandler func(Page) error

func invalidResponse(endpoint, reason string) *APIError {
	return &APIError{Class: ClassInvalidResponse, Endpoint: endpoint, Status: http.StatusOK, Err: errors.New(reason)}
}

func notReady(endpoint string, status int, reason string) *APIError {
	return &APIError{Class: ClassNotReady, Endpoint: endpoint, Status: status, Err: errors.New(reason)}
}

// FetchAccrualByDay выгружает начисления дня (YYYY-MM-DD) постранично, начиная
// с fromCursor ("" — с начала). Номер первой страницы — firstPageNo.
func (c *Client) FetchAccrualByDay(ctx context.Context, ref account.Ref, creds Credentials, day, fromCursor string, firstPageNo int, onPage PageHandler) error {
	cursor := strings.TrimSpace(fromCursor)
	seen := map[string]bool{}
	if cursor != "" {
		seen[cursor] = true
	}

	for no := firstPageNo; ; no++ {
		if no-firstPageNo >= MaxAccrualPages {
			return invalidResponse(EndpointAccrualByDay, fmt.Sprintf("more than %d pages with a non-empty cursor", MaxAccrualPages))
		}

		req := map[string]string{"date": day}
		if cursor != "" {
			req["last_id"] = cursor
		}
		resp, err := c.Post(ctx, ref, creds, EndpointAccrualByDay, req)
		if err != nil {
			return err
		}

		var shape struct {
			Accruals json.RawMessage `json:"accruals"`
			LastID   *string         `json:"last_id"`
		}
		if err := json.Unmarshal(resp.Body, &shape); err != nil || !isJSONArray(shape.Accruals) {
			return invalidResponse(EndpointAccrualByDay, `response has no "accruals" array`)
		}

		next := ""
		if shape.LastID != nil {
			next = strings.TrimSpace(*shape.LastID)
		}
		if next != "" && seen[next] {
			return invalidResponse(EndpointAccrualByDay, fmt.Sprintf("repeated pagination cursor on page %d", no))
		}
		seen[next] = true

		if err := onPage(Page{No: no, Body: resp.Body, Cursor: next, ContentType: "application/json"}); err != nil {
			return err
		}
		if next == "" {
			return nil
		}
		cursor = next
	}
}

// FetchAccrualTypes — справочник типов начислений.
func (c *Client) FetchAccrualTypes(ctx context.Context, ref account.Ref, creds Credentials) (Page, error) {
	resp, err := c.Post(ctx, ref, creds, EndpointAccrualTypes, struct{}{})
	if err != nil {
		return Page{}, err
	}
	var shape struct {
		Types json.RawMessage `json:"accrual_types"`
	}
	if err := json.Unmarshal(resp.Body, &shape); err != nil || !isJSONArray(shape.Types) {
		return Page{}, invalidResponse(EndpointAccrualTypes, `response has no "accrual_types" array`)
	}
	return Page{No: 1, Body: resp.Body, ContentType: "application/json"}, nil
}

// FetchRealization — отчёт о реализации за закрытый месяц. Пустые rows и 4xx —
// not_ready: так Ozon отвечает на ещё не сформированный месяц.
func (c *Client) FetchRealization(ctx context.Context, ref account.Ref, creds Credentials, year, month int) (Page, error) {
	resp, err := c.Post(ctx, ref, creds, EndpointRealization, map[string]int{"month": month, "year": year})
	if err != nil {
		var apiErr *APIError
		if errors.As(err, &apiErr) && apiErr.Class == ClassInvalidRequest {
			return Page{}, notReady(EndpointRealization, apiErr.Status, "report is not formed yet")
		}
		return Page{}, err
	}
	var shape struct {
		Result struct {
			Rows json.RawMessage `json:"rows"`
		} `json:"result"`
	}
	if err := json.Unmarshal(resp.Body, &shape); err != nil {
		return Page{}, invalidResponse(EndpointRealization, "response is not a JSON object")
	}
	if !isJSONArray(shape.Result.Rows) || isEmptyJSONArray(shape.Result.Rows) {
		return Page{}, notReady(EndpointRealization, resp.Status, "empty rows")
	}
	return Page{No: 1, Body: resp.Body, ContentType: "application/json"}, nil
}

// ReportPoll — опрос асинхронного отчёта: интервал растёт ×2 до Max, общий
// предел — Deadline. Не готов к дедлайну — not_ready, отчёт заказывается снова.
type ReportPoll struct {
	Initial  time.Duration
	Max      time.Duration
	Deadline time.Duration
}

// DefaultReportPoll — как в последней версии PHP-клиента (2 с → 60 с).
var DefaultReportPoll = ReportPoll{Initial: 2 * time.Second, Max: 30 * time.Second, Deadline: 60 * time.Second}

// FetchMutualSettlement — отчёт «Взаиморасчёты» за месяц: заказ → опрос
// /v1/report/info → скачивание файла. Raw — байты файла.
//
// Форма ответов не подтверждена на проде (клиент Symfony не работал); разбор
// терпимый, как в последней версии PHP-клиента (docs/ozon-api.md).
func (c *Client) FetchMutualSettlement(ctx context.Context, ref account.Ref, creds Credentials, year, month int, poll ReportPoll, sleep func(context.Context, time.Duration) error) (Page, error) {
	resp, err := c.Post(ctx, ref, creds, EndpointMutualSettlement, map[string]string{
		"date":     fmt.Sprintf("%04d-%02d", year, month),
		"language": "DEFAULT",
	})
	if err != nil {
		return Page{}, err
	}
	code := firstString(resp.Body, []string{"result", "code"}, []string{"code"}, []string{"report_code"})
	if code == "" {
		return Page{}, invalidResponse(EndpointMutualSettlement, "response has no report code")
	}

	// Дедлайн опроса — по реальному времени: включает запросы статуса и
	// ожидание лимитера, а не только паузы между ними.
	pollCtx, cancel := context.WithTimeout(ctx, poll.Deadline)
	defer cancel()
	pollExpired := func(err error) bool {
		return ctx.Err() == nil && errors.Is(err, context.DeadlineExceeded) && pollCtx.Err() != nil
	}

	delay, waited := poll.Initial, time.Duration(0)
	for {
		if err := sleep(pollCtx, delay); err != nil {
			if pollExpired(err) {
				return Page{}, notReady(EndpointReportInfo, 0, "report is not ready before deadline")
			}
			return Page{}, err
		}
		waited += delay

		info, err := c.Post(pollCtx, ref, creds, EndpointReportInfo, map[string]string{"code": code})
		if err != nil {
			if pollExpired(err) {
				return Page{}, notReady(EndpointReportInfo, 0, "report is not ready before deadline")
			}
			return Page{}, err
		}
		switch strings.ToLower(firstString(info.Body, []string{"result", "status"}, []string{"status"})) {
		case "success", "completed":
			file := firstString(info.Body, []string{"result", "file"}, []string{"file"})
			if file == "" {
				return Page{}, invalidResponse(EndpointReportInfo, "ready report has no file link")
			}
			return c.download(ctx, ref, file)
		case "failed", "error":
			return Page{}, &APIError{Class: ClassTransient, Endpoint: EndpointReportInfo, Status: info.Status, Err: errors.New("report generation failed")}
		}

		if waited >= poll.Deadline || pollCtx.Err() != nil {
			return Page{}, notReady(EndpointReportInfo, info.Status, "report is not ready before deadline")
		}
		delay = min(delay*2, poll.Max, poll.Deadline-waited)
	}
}

// download — GET по pre-signed ссылке без ключей кабинета; только https.
func (c *Client) download(ctx context.Context, ref account.Ref, link string) (Page, error) {
	const endpoint = "report-file"
	u, err := url.Parse(link)
	if err != nil || u.Scheme != "https" || u.Host == "" {
		return Page{}, invalidResponse(endpoint, "report file link is not an https URL")
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u.String(), nil)
	if err != nil {
		return Page{}, fmt.Errorf("ozon: build download request: %w", err)
	}

	started := c.now()
	resp, err := c.http.Do(req)
	if err != nil {
		if ctxErr := ctx.Err(); ctxErr != nil {
			return Page{}, ctxErr
		}
		c.log(ctx, ref, endpoint, 0, c.now().Sub(started), string(ClassTransient))
		return Page{}, &APIError{Class: ClassTransient, Endpoint: endpoint, Err: withoutURL(err)}
	}
	defer func() { _ = resp.Body.Close() }()

	raw, err := io.ReadAll(io.LimitReader(resp.Body, MaxBodyBytes+1))
	duration := c.now().Sub(started)
	switch {
	case err != nil:
		if ctxErr := ctx.Err(); ctxErr != nil {
			return Page{}, ctxErr
		}
		c.log(ctx, ref, endpoint, resp.StatusCode, duration, string(ClassTransient))
		return Page{}, &APIError{Class: ClassTransient, Endpoint: endpoint, Status: resp.StatusCode, Err: withoutURL(err)}
	case len(raw) > MaxBodyBytes:
		c.log(ctx, ref, endpoint, resp.StatusCode, duration, string(ClassInvalidResponse))
		return Page{}, &APIError{Class: ClassInvalidResponse, Endpoint: endpoint, Status: resp.StatusCode, Err: ErrBodyTooLarge}
	case resp.StatusCode >= 500:
		c.log(ctx, ref, endpoint, resp.StatusCode, duration, string(ClassTransient))
		return Page{}, &APIError{Class: ClassTransient, Endpoint: endpoint, Status: resp.StatusCode, Err: errors.New("report file download failed")}
	case resp.StatusCode != http.StatusOK:
		// Истёкшая pre-signed ссылка: отчёт заказывается заново на следующей попытке.
		c.log(ctx, ref, endpoint, resp.StatusCode, duration, string(ClassNotReady))
		return Page{}, notReady(endpoint, resp.StatusCode, "report file is not available")
	}

	c.log(ctx, ref, endpoint, resp.StatusCode, duration, "ok")
	contentType := resp.Header.Get("Content-Type")
	// Ozon отдаёт отчёт как application/octet-stream (замер 17.10.2026);
	// отчёт взаиморасчётов — XLSX, узнаём по сигнатуре zip.
	if !strings.Contains(contentType, "spreadsheetml") && bytes.HasPrefix(raw, []byte("PK\x03\x04")) {
		contentType = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
	}
	return Page{No: 1, Body: raw, ContentType: contentType}, nil
}

// withoutURL убирает из ошибки транспорта адрес запроса: pre-signed ссылка
// несёт подпись в query, и она не должна попасть в лог через APIError.Error().
func withoutURL(err error) error {
	var urlErr *url.Error
	if errors.As(err, &urlErr) {
		return fmt.Errorf("%s report file: %w", urlErr.Op, urlErr.Err)
	}
	return err
}

// SleepContext — пауза с отменой по контексту.
func SleepContext(ctx context.Context, d time.Duration) error {
	if d <= 0 {
		return ctx.Err()
	}
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}

func isJSONArray(raw json.RawMessage) bool {
	trimmed := strings.TrimSpace(string(raw))
	return strings.HasPrefix(trimmed, "[") && json.Valid(raw)
}

func isEmptyJSONArray(raw json.RawMessage) bool {
	var items []json.RawMessage
	return json.Unmarshal(raw, &items) == nil && len(items) == 0
}

// firstString — первое непустое строковое (или числовое) значение по путям.
func firstString(body []byte, paths ...[]string) string {
	var doc any
	if json.Unmarshal(body, &doc) != nil {
		return ""
	}
	for _, path := range paths {
		node := doc
		for _, key := range path {
			obj, ok := node.(map[string]any)
			if !ok {
				node = nil
				break
			}
			node = obj[key]
		}
		switch v := node.(type) {
		case string:
			if s := strings.TrimSpace(v); s != "" {
				return s
			}
		case float64:
			return fmt.Sprintf("%.0f", v)
		}
	}
	return ""
}
