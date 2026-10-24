package ozonperf

import (
	"encoding/json"
	"fmt"
	"sort"
	"strconv"
	"strings"
)

// SKUCampaigns — id кампаний «Трафареты» (advObjectType = SKU) из страницы
// списка кампаний, по возрастанию без дублей. Остальные типы отчёт по
// товарам не строит (как Symfony MarketplaceAds).
func SKUCampaigns(body []byte) ([]string, error) {
	var shape struct {
		List *[]map[string]any `json:"list"`
	}
	if err := json.Unmarshal(body, &shape); err != nil || shape.List == nil {
		return nil, fmt.Errorf(`no "list" array`)
	}
	set := map[string]bool{}
	for _, c := range *shape.List {
		if t, _ := c["advObjectType"].(string); t != "SKU" {
			continue
		}
		id := idString(c["id"])
		if id == "" {
			return nil, fmt.Errorf("SKU campaign without id")
		}
		set[id] = true
	}
	return sortedIDs(set), nil
}

// ActiveCampaigns — id кампаний с ненулевыми показами, кликами или расходом
// на странице дневной статистики. ok=false — форма страницы незнакома
// (нет массива строк с id кампании): вызывающий берёт все кампании.
func ActiveCampaigns(body []byte) (map[string]bool, bool) {
	var top map[string]any
	if err := json.Unmarshal(body, &top); err != nil {
		return nil, false
	}
	var rows []any
	for _, k := range []string{"rows", "list", "items", "data"} {
		if arr, ok := top[k].([]any); ok {
			rows = arr
			break
		}
	}
	if rows == nil {
		return nil, false
	}
	active := map[string]bool{}
	for _, raw := range rows {
		row, ok := raw.(map[string]any)
		if !ok {
			return nil, false
		}
		id := idString(row["id"])
		if id == "" {
			id = idString(row["campaignId"])
		}
		if id == "" {
			return nil, false
		}
		known := 0
		for _, k := range []string{"views", "clicks", "moneySpent"} {
			v, present := row[k]
			if !present {
				continue
			}
			f, ok := number(v)
			if !ok {
				// Значение не число — форма незнакома.
				return nil, false
			}
			known++
			if f > 0 {
				active[id] = true
			}
		}
		if known == 0 {
			// Ни одной известной метрики — пустой список был бы тихой потерей.
			return nil, false
		}
	}
	return active, true
}

func idString(v any) string {
	switch t := v.(type) {
	case string:
		return strings.TrimSpace(t)
	case float64:
		if t > 0 && t == float64(int64(t)) {
			return strconv.FormatInt(int64(t), 10)
		}
	}
	return ""
}

// number — число или строка числа (Ozon отдаёт «123,45»).
func number(v any) (float64, bool) {
	switch t := v.(type) {
	case float64:
		return t, true
	case string:
		f, err := strconv.ParseFloat(strings.ReplaceAll(strings.TrimSpace(t), ",", "."), 64)
		return f, err == nil
	}
	return 0, false
}

func sortedIDs(set map[string]bool) []string {
	out := make([]string, 0, len(set))
	for id := range set {
		out = append(out, id)
	}
	sort.Slice(out, func(i, j int) bool {
		a, errA := strconv.ParseInt(out[i], 10, 64)
		b, errB := strconv.ParseInt(out[j], 10, 64)
		if errA == nil && errB == nil {
			return a < b
		}
		return out[i] < out[j]
	})
	return out
}
