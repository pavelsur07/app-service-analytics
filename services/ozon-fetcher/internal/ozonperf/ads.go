package ozonperf

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/url"
	"sort"
	"strings"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
)

// Синхронные методы рекламы
// (docs/tasks/marketplace-data-ingestion/stages/ozon-6-ads-sync.md §2).
const (
	EndpointCampaigns = "/api/client/campaign"
	EndpointExpense   = "/api/client/statistics/expense/json"
	EndpointDaily     = "/api/client/statistics/daily/json"
)

// FetchCampaigns — список всех кампаний аккаунта (все типы и состояния).
// Ответ — объект с массивом list.
func (c *Client) FetchCampaigns(ctx context.Context, ref account.Ref, creds Credentials) (ozon.Page, error) {
	resp, err := c.Get(ctx, ref, creds, EndpointCampaigns, nil)
	if err != nil {
		return ozon.Page{}, err
	}
	var shape struct {
		List json.RawMessage `json:"list"`
	}
	if err := json.Unmarshal(resp.Body, &shape); err != nil || !isArray(shape.List) {
		return ozon.Page{}, invalid(EndpointCampaigns, resp.Status, `response has no "list" array`)
	}
	return ozon.Page{No: 1, Body: resp.Body, ContentType: contentType(resp)}, nil
}

// FetchExpense — расход аккаунта за день (dateFrom = dateTo).
func (c *Client) FetchExpense(ctx context.Context, ref account.Ref, creds Credentials, day string) (ozon.Page, error) {
	return c.fetchDay(ctx, ref, creds, EndpointExpense, day)
}

// FetchDaily — дневная статистика по кампаниям за день (dateFrom = dateTo).
func (c *Client) FetchDaily(ctx context.Context, ref account.Ref, creds Credentials, day string) (ozon.Page, error) {
	return c.fetchDay(ctx, ref, creds, EndpointDaily, day)
}

// fetchDay — день как пришёл; форма ответа вживую не проверена (нет тестового
// ключа), поэтому проверяется только JSON-объект или массив.
func (c *Client) fetchDay(ctx context.Context, ref account.Ref, creds Credentials, endpoint, day string) (ozon.Page, error) {
	resp, err := c.Get(ctx, ref, creds, endpoint, url.Values{"dateFrom": {day}, "dateTo": {day}})
	if err != nil {
		return ozon.Page{}, err
	}
	trimmed := bytes.TrimSpace(resp.Body)
	if !json.Valid(trimmed) || len(trimmed) == 0 || (trimmed[0] != '{' && trimmed[0] != '[') {
		return ozon.Page{}, invalid(endpoint, resp.Status, "response is not a JSON object or array")
	}
	return ozon.Page{No: 1, Body: resp.Body, ContentType: contentType(resp)}, nil
}

func contentType(resp ozon.Response) string {
	if ct := resp.Header.Get("Content-Type"); ct != "" {
		return ct
	}
	return "application/json"
}

func isArray(raw json.RawMessage) bool {
	trimmed := bytes.TrimSpace(raw)
	return len(trimmed) > 0 && trimmed[0] == '['
}

func invalid(endpoint string, status int, reason string) error {
	return &ozon.APIError{Class: ozon.ClassInvalidResponse, Endpoint: endpoint, Status: status, Err: errors.New(reason)}
}

// Shape — имена полей ответа без значений: ключи верхнего уровня и ключи
// первого объекта в каждом массиве объектов. Для журнала: живая форма
// ответов рекламы не проверена (нет тестового ключа), значения в журнал не
// попадают.
func Shape(body []byte) string {
	var top any
	if err := json.Unmarshal(body, &top); err != nil {
		return "not json"
	}
	describe := func(v any) string {
		obj, ok := v.(map[string]any)
		if !ok {
			return fmt.Sprintf("%T", v)
		}
		keys := make([]string, 0, len(obj))
		for k := range obj {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		return "{" + strings.Join(keys, ",") + "}"
	}
	switch t := top.(type) {
	case []any:
		if len(t) == 0 {
			return "[]"
		}
		return fmt.Sprintf("[%d]%s", len(t), describe(t[0]))
	case map[string]any:
		parts := []string{describe(t)}
		keys := make([]string, 0, len(t))
		for k := range t {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		for _, k := range keys {
			if arr, ok := t[k].([]any); ok {
				if len(arr) == 0 {
					parts = append(parts, k+"=[]")
				} else {
					parts = append(parts, fmt.Sprintf("%s=[%d]%s", k, len(arr), describe(arr[0])))
				}
			}
		}
		return strings.Join(parts, " ")
	}
	return fmt.Sprintf("%T", top)
}
