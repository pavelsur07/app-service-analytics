package ozon

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
)

// EndpointAccrualPostings — начисления по номерам отправлений.
const EndpointAccrualPostings = "/v1/finance/accrual/postings"

// MaxAccrualPostingNumbers — предел Ozon на запрос (201 → 400 «between 1 and
// 200 items», живая проверка 21.10.2026).
const MaxAccrualPostingNumbers = 200

// FetchAccrualPostings — начисления по отправлениям numbers одной страницей.
// Ответ сверяется с запросом: Ozon возвращает элемент на каждый номер, в том
// числе с пустым accruals; недостающий или лишний номер — тихая потеря, это
// invalid_response.
func (c *Client) FetchAccrualPostings(ctx context.Context, ref account.Ref, creds Credentials, numbers []string) (Page, error) {
	if len(numbers) == 0 || len(numbers) > MaxAccrualPostingNumbers {
		return Page{}, fmt.Errorf("ozon: accrual postings need 1..%d posting numbers, got %d", MaxAccrualPostingNumbers, len(numbers))
	}
	requested := make(map[string]bool, len(numbers))
	for _, n := range numbers {
		n = strings.TrimSpace(n)
		if n == "" || requested[n] {
			return Page{}, fmt.Errorf("ozon: accrual postings: empty or duplicate posting number %q", n)
		}
		requested[n] = true
	}

	resp, err := c.Post(ctx, ref, creds, EndpointAccrualPostings, map[string][]string{"posting_numbers": numbers})
	if err != nil {
		return Page{}, err
	}

	var shape struct {
		PostingAccruals []struct {
			PostingNumber string          `json:"posting_number"`
			Accruals      json.RawMessage `json:"accruals"`
		} `json:"posting_accruals"`
	}
	if err := json.Unmarshal(resp.Body, &shape); err != nil || shape.PostingAccruals == nil {
		return Page{}, invalidResponse(EndpointAccrualPostings, `response has no "posting_accruals" array`)
	}
	seen := make(map[string]bool, len(shape.PostingAccruals))
	for _, pa := range shape.PostingAccruals {
		if !requested[pa.PostingNumber] || seen[pa.PostingNumber] {
			return Page{}, invalidResponse(EndpointAccrualPostings, fmt.Sprintf("unexpected or repeated posting %q in response", pa.PostingNumber))
		}
		if !isJSONArray(pa.Accruals) {
			return Page{}, invalidResponse(EndpointAccrualPostings, fmt.Sprintf(`posting %q has no "accruals" array`, pa.PostingNumber))
		}
		seen[pa.PostingNumber] = true
	}
	if len(seen) != len(requested) {
		return Page{}, invalidResponse(EndpointAccrualPostings, fmt.Sprintf("response covers %d of %d requested postings", len(seen), len(requested)))
	}
	return Page{No: 1, Body: resp.Body, ContentType: "application/json"}, nil
}
