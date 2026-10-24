package ozon

import (
	"context"
	"fmt"
	"net/http"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func accrualPostingsServer(t *testing.T, respond func(numbers []any) (int, string)) (http.Handler, *[]map[string]any) {
	var bodies []map[string]any
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		assert.Equal(t, EndpointAccrualPostings, r.URL.Path)
		body := decodeBody(t, r)
		bodies = append(bodies, body)
		numbers, _ := body["posting_numbers"].([]any)
		status, out := respond(numbers)
		w.WriteHeader(status)
		_, _ = w.Write([]byte(out))
	}), &bodies
}

// echo — живая форма: элемент на каждый номер, у части — пустой accruals.
func echo(numbers []any) (int, string) {
	out := `{"posting_accruals":[`
	for i, n := range numbers {
		if i > 0 {
			out += ","
		}
		accruals := `[]`
		if i == 0 {
			accruals = `[{"type_id":29,"accrued":{"value":-12.5},"accrual_date":"2026-10-03","sku":1,"quantity":1,"seller_price":null}]`
		}
		out += fmt.Sprintf(`{"posting_number":%q,"accruals":%s}`, n, accruals)
	}
	return http.StatusOK, out + `]}`
}

func TestFetchAccrualPostingsSendsOnlyPostingNumbersAndKeepsBody(t *testing.T) {
	handler, bodies := accrualPostingsServer(t, echo)
	client := clientFor(t, handler)

	page, err := client.FetchAccrualPostings(context.Background(), testRef, testCreds, []string{"A-1", "B-2"})

	require.NoError(t, err)
	assert.Equal(t, []map[string]any{{"posting_numbers": []any{"A-1", "B-2"}}}, *bodies)
	_, want := echo([]any{"A-1", "B-2"})
	assert.Equal(t, want, string(page.Body))
	assert.Equal(t, 1, page.No)
}

func TestFetchAccrualPostingsRejectsBadInputWithoutRequest(t *testing.T) {
	client := clientFor(t, http.HandlerFunc(func(http.ResponseWriter, *http.Request) { t.Fatal("no request expected") }))
	tooMany := make([]string, MaxAccrualPostingNumbers+1)
	for i := range tooMany {
		tooMany[i] = fmt.Sprintf("P-%d", i)
	}
	for name, numbers := range map[string][]string{"none": nil, "too many": tooMany, "duplicate": {"A", "A"}, "empty": {"A", " "}} {
		_, err := client.FetchAccrualPostings(context.Background(), testRef, testCreds, numbers)
		require.Error(t, err, name)
	}
}

func TestFetchAccrualPostingsResponseMustCoverRequest(t *testing.T) {
	cases := map[string]string{
		"no array":        `{"result":[]}`,
		"missing posting": `{"posting_accruals":[{"posting_number":"A-1","accruals":[]}]}`,
		"foreign posting": `{"posting_accruals":[{"posting_number":"A-1","accruals":[]},{"posting_number":"X-9","accruals":[]}]}`,
		"repeated":        `{"posting_accruals":[{"posting_number":"A-1","accruals":[]},{"posting_number":"A-1","accruals":[]}]}`,
		"no accruals":     `{"posting_accruals":[{"posting_number":"A-1"},{"posting_number":"B-2","accruals":[]}]}`,
	}
	for name, body := range cases {
		t.Run(name, func(t *testing.T) {
			handler, _ := accrualPostingsServer(t, func([]any) (int, string) { return http.StatusOK, body })
			_, err := clientFor(t, handler).FetchAccrualPostings(context.Background(), testRef, testCreds, []string{"A-1", "B-2"})

			var apiErr *APIError
			require.ErrorAs(t, err, &apiErr)
			assert.Equal(t, ClassInvalidResponse, apiErr.Class)
		})
	}
}

func TestFetchAccrualPostingsErrorClasses(t *testing.T) {
	for status, tc := range map[int]struct {
		body  string
		class Class
	}{
		http.StatusBadRequest:      {`{"code":3,"message":"value must contain between 1 and 200 items, inclusive"}`, ClassInvalidRequest},
		http.StatusTooManyRequests: {`{"code":8,"message":"too many requests"}`, ClassRateLimit},
	} {
		handler, _ := accrualPostingsServer(t, func([]any) (int, string) { return status, tc.body })
		_, err := clientFor(t, handler).FetchAccrualPostings(context.Background(), testRef, testCreds, []string{"A-1"})
		var apiErr *APIError
		require.ErrorAs(t, err, &apiErr)
		assert.Equal(t, tc.class, apiErr.Class)
	}
	handler, _ := accrualPostingsServer(t, func([]any) (int, string) {
		return http.StatusBadRequest, `{"code":9,"message":"obsolete method cannot be used"}`
	})
	_, err := clientFor(t, handler).FetchAccrualPostings(context.Background(), testRef, testCreds, []string{"A-1"})
	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ClassObsoleteEndpoint, apiErr.Class)
}
