package ozon

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
)

// Остатки — снимок по всем товарам кабинета
// (docs/tasks/marketplace-data-ingestion/stages/ozon-5-stocks.md §2).
const EndpointStocks = "/v4/product/info/stocks"

// StocksPageLimit — максимум Ozon (1001 → 400 «inside range (0, 1000]»).
const StocksPageLimit = 1000

// MaxStocksPages — защитный предел страниц снимка (миллион товаров).
const MaxStocksPages = 1000

// stocksShape — страница остатков: без result, курсор — cursor.
type stocksShape struct {
	Items  *[]stocksItem `json:"items"`
	Cursor *string       `json:"cursor"`
	Total  *int64        `json:"total"`
}

type stocksItem struct {
	Stocks *[]struct {
		SKU *int64 `json:"sku"`
	} `json:"stocks"`
}

// StocksPage — счётчики страницы: товаров, разных SKU в stocks[] и total.
type StocksPage struct {
	Items int
	SKUs  []int64
	Total int64
	Next  string
}

// ParseStocksPage проверяет страницу снимка. Конец снимка Ozon сообщает
// пустой страницей: последняя страница с товарами отдаёт непустой cursor
// (живая проверка 22.10.2026).
func ParseStocksPage(body []byte) (StocksPage, error) {
	var shape stocksShape
	if err := json.Unmarshal(body, &shape); err != nil {
		return StocksPage{}, invalidResponse(EndpointStocks, "response is not a JSON object")
	}
	if shape.Items == nil {
		return StocksPage{}, invalidResponse(EndpointStocks, `response has no "items" array`)
	}
	if shape.Cursor == nil {
		return StocksPage{}, invalidResponse(EndpointStocks, `response has no string "cursor"`)
	}
	if shape.Total == nil || *shape.Total < 0 {
		return StocksPage{}, invalidResponse(EndpointStocks, `response has no non-negative "total"`)
	}
	page := StocksPage{Items: len(*shape.Items), Total: *shape.Total}
	for _, it := range *shape.Items {
		if it.Stocks == nil {
			return StocksPage{}, invalidResponse(EndpointStocks, `item has no "stocks" array`)
		}
		for _, s := range *it.Stocks {
			if s.SKU != nil && *s.SKU > 0 {
				page.SKUs = append(page.SKUs, *s.SKU)
			}
		}
	}
	if page.Items == 0 {
		// Пустая страница завершает обход и при непустом cursor.
		return page, nil
	}
	page.Next = strings.TrimSpace(*shape.Cursor)
	if page.Next == "" {
		// Конец подтверждает только пустая страница: товары без курсора —
		// тихая обрезка снимка.
		return StocksPage{}, invalidResponse(EndpointStocks, "non-empty page without cursor")
	}
	return page, nil
}

// FetchStocks выгружает снимок остатков (visibility ALL) постранично, начиная
// с fromCursor ("" — с начала). Каждая страница, включая концевую пустую,
// отдаётся в onPage до запроса следующей; Page.Cursor — курсор следующей
// страницы, "" у последней.
func (c *Client) FetchStocks(ctx context.Context, ref account.Ref, creds Credentials, fromCursor string, firstPageNo int, onPage PageHandler) error {
	cursor := strings.TrimSpace(fromCursor)
	seen := map[string]bool{}
	if cursor != "" {
		seen[cursor] = true
	}
	for no := firstPageNo; ; no++ {
		if no > MaxStocksPages {
			return invalidResponse(EndpointStocks, fmt.Sprintf("more than %d pages with a continuation marker", MaxStocksPages))
		}
		body := map[string]any{
			"filter": map[string]string{"visibility": "ALL"},
			"limit":  StocksPageLimit,
		}
		if cursor != "" {
			body["cursor"] = cursor
		}
		resp, err := c.Post(ctx, ref, creds, EndpointStocks, body)
		if err != nil {
			return err
		}
		page, err := ParseStocksPage(resp.Body)
		if err != nil {
			return err
		}
		if page.Next != "" && seen[page.Next] {
			// Курсор не продвинулся при непустой странице — обход крутился бы вечно.
			return invalidResponse(EndpointStocks, fmt.Sprintf("repeated cursor on page %d", no))
		}
		seen[page.Next] = true

		if err := onPage(Page{No: no, Body: resp.Body, Cursor: page.Next, ContentType: "application/json"}); err != nil {
			return err
		}
		if page.Next == "" {
			return nil
		}
		cursor = page.Next
	}
}
