package ozon

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
)

// Каталог товаров: список всех товаров и подробности по product_id
// (docs/tasks/marketplace-data-ingestion/stages/ozon-4-products.md §2).
const (
	EndpointProductList     = "/v3/product/list"
	EndpointProductInfoList = "/v3/product/info/list"
)

// ProductListPageLimit — максимум Ozon (1001 → 400 «inside range (0, 1000]»).
const ProductListPageLimit = 1000

// MaxProductInfoIDs — предел product_id в запросе подробностей (1001 → 400).
const MaxProductInfoIDs = 1000

// MaxProductListPages — защитный предел страниц списка (миллион товаров);
// номер страницы абсолютный, продолжение после рестарта его не обходит.
const MaxProductListPages = 1000

// productListShape — страница списка: всё внутри result.
type productListShape struct {
	Result *struct {
		Items  json.RawMessage `json:"items"`
		LastID *string         `json:"last_id"`
		Total  *int64          `json:"total"`
	} `json:"result"`
}

// parseProductListPage — число товаров и last_id следующей страницы ("" —
// конец). Конец списка Ozon сообщает пустой страницей: последняя страница с
// товарами отдаёт непустой last_id (живая проверка 21.10.2026).
func parseProductListPage(body []byte) (int, string, error) {
	var shape productListShape
	if err := json.Unmarshal(body, &shape); err != nil || shape.Result == nil {
		return 0, "", invalidResponse(EndpointProductList, `response has no "result" object`)
	}
	r := shape.Result
	if !isJSONArray(r.Items) {
		return 0, "", invalidResponse(EndpointProductList, `response has no "result.items" array`)
	}
	if r.LastID == nil {
		return 0, "", invalidResponse(EndpointProductList, `response has no string "result.last_id"`)
	}
	if r.Total == nil || *r.Total < 0 {
		return 0, "", invalidResponse(EndpointProductList, `response has no non-negative "result.total"`)
	}
	var items []json.RawMessage
	_ = json.Unmarshal(r.Items, &items)
	if len(items) == 0 {
		// Пустая страница завершает обход и при непустом last_id.
		return 0, "", nil
	}
	next := strings.TrimSpace(*r.LastID)
	if next == "" {
		// Конец подтверждает только пустая страница: товары без курсора —
		// тихая обрезка каталога.
		return 0, "", invalidResponse(EndpointProductList, "non-empty page without last_id")
	}
	return len(items), next, nil
}

// FetchProductList выгружает список всех товаров кабинета (visibility ALL)
// постранично, начиная с fromLastID ("" — с начала). Каждая страница, включая
// концевую пустую, отдаётся в onPage до запроса следующей; Page.Cursor —
// last_id следующей страницы, "" у последней.
func (c *Client) FetchProductList(ctx context.Context, ref account.Ref, creds Credentials, fromLastID string, firstPageNo int, onPage PageHandler) error {
	lastID := strings.TrimSpace(fromLastID)
	seen := map[string]bool{}
	if lastID != "" {
		seen[lastID] = true
	}
	for no := firstPageNo; ; no++ {
		if no > MaxProductListPages {
			return invalidResponse(EndpointProductList, fmt.Sprintf("more than %d pages with a continuation marker", MaxProductListPages))
		}
		resp, err := c.Post(ctx, ref, creds, EndpointProductList, map[string]any{
			"filter":  map[string]string{"visibility": "ALL"},
			"last_id": lastID,
			"limit":   ProductListPageLimit,
		})
		if err != nil {
			return err
		}
		_, next, err := parseProductListPage(resp.Body)
		if err != nil {
			return err
		}
		if next != "" && seen[next] {
			// Курсор не продвинулся при непустой странице — обход крутился бы вечно.
			return invalidResponse(EndpointProductList, fmt.Sprintf("repeated last_id on page %d", no))
		}
		seen[next] = true

		if err := onPage(Page{No: no, Body: resp.Body, Cursor: next, ContentType: "application/json"}); err != nil {
			return err
		}
		if next == "" {
			return nil
		}
		lastID = next
	}
}

// ProductListIDs — product_id и total страницы списка (чтение сохранённого raw).
func ProductListIDs(body []byte) ([]int64, int64, error) {
	var shape struct {
		Result *struct {
			Items *[]struct {
				ProductID *int64 `json:"product_id"`
			} `json:"items"`
			Total *int64 `json:"total"`
		} `json:"result"`
	}
	if err := json.Unmarshal(body, &shape); err != nil {
		return nil, 0, err
	}
	if shape.Result == nil || shape.Result.Items == nil {
		return nil, 0, fmt.Errorf(`no "result.items" array`)
	}
	var total int64
	if shape.Result.Total != nil {
		total = *shape.Result.Total
	}
	out := make([]int64, 0, len(*shape.Result.Items))
	for _, it := range *shape.Result.Items {
		if it.ProductID == nil || *it.ProductID <= 0 {
			return nil, 0, fmt.Errorf("item without positive product_id")
		}
		out = append(out, *it.ProductID)
	}
	return out, total, nil
}

// FetchProductInfo — подробности товаров ids одной страницей. Каждый id
// ответа — из запроса и без повторов, иначе invalid_response. Неизвестные id
// Ozon молча пропускает (удалённый товар): их число — missing, решение — у
// вызывающего.
func (c *Client) FetchProductInfo(ctx context.Context, ref account.Ref, creds Credentials, ids []int64) (Page, int, error) {
	if len(ids) == 0 || len(ids) > MaxProductInfoIDs {
		return Page{}, 0, fmt.Errorf("ozon: product info needs 1..%d product ids, got %d", MaxProductInfoIDs, len(ids))
	}
	requested := make(map[int64]bool, len(ids))
	for _, id := range ids {
		if id <= 0 || requested[id] {
			return Page{}, 0, fmt.Errorf("ozon: product info: non-positive or duplicate product id %d", id)
		}
		requested[id] = true
	}

	resp, err := c.Post(ctx, ref, creds, EndpointProductInfoList, map[string][]int64{"product_id": ids})
	if err != nil {
		return Page{}, 0, err
	}

	var shape struct {
		Items *[]struct {
			ID *int64 `json:"id"`
		} `json:"items"`
	}
	if err := json.Unmarshal(resp.Body, &shape); err != nil || shape.Items == nil {
		return Page{}, 0, invalidResponse(EndpointProductInfoList, `response has no "items" array`)
	}
	seen := make(map[int64]bool, len(*shape.Items))
	for _, it := range *shape.Items {
		if it.ID == nil || !requested[*it.ID] || seen[*it.ID] {
			id := int64(0)
			if it.ID != nil {
				id = *it.ID
			}
			return Page{}, 0, invalidResponse(EndpointProductInfoList, fmt.Sprintf("unexpected or repeated product id %d in response", id))
		}
		seen[*it.ID] = true
	}
	return Page{No: 1, Body: resp.Body, ContentType: "application/json"}, len(requested) - len(seen), nil
}
