package ozonperf

import (
	"context"
	"net/http"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozonfixture"
)

// Ответы кабинета Conwix (api/tests/Fixtures/Marketplace/ozon/performance):
// страница уходит в raw побайтово, разбор — на боевой форме.

func serveFixture(body []byte) *fakePerf {
	return &fakePerf{ttl: 1800, handle: func(w http.ResponseWriter, _ *http.Request, _ string) {
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		_, _ = w.Write(body)
	}}
}

func TestCampaignsRealListAndSKUCampaigns(t *testing.T) {
	body := ozonfixture.Read(t, "performance/campaign-list.json")
	c, _, _ := newTestClient(t, serveFixture(body))

	page, err := c.FetchCampaigns(context.Background(), testRef, testCreds)

	require.NoError(t, err)
	assert.Equal(t, body, page.Body)
	ids, err := SKUCampaigns(page.Body)
	require.NoError(t, err)
	assert.Len(t, ids, 58, "из 94 кампаний пяти типов — только трафареты")
}

func TestExpenseAndDailyRealPagesAreStoredAsIs(t *testing.T) {
	for name, file := range map[string]string{
		"expense": "performance/statistics-expense-2026-08-25.json",
		"daily":   "performance/statistics-daily-2026-08-25.json",
	} {
		t.Run(name, func(t *testing.T) {
			body := ozonfixture.Read(t, file)
			c, _, _ := newTestClient(t, serveFixture(body))
			fetch := c.FetchExpense
			if name == "daily" {
				fetch = c.FetchDaily
			}

			page, err := fetch(context.Background(), testRef, testCreds, "2026-08-25")

			require.NoError(t, err)
			assert.Equal(t, body, page.Body)
			assert.Equal(t, "application/json; charset=utf-8", page.ContentType)
		})
	}
}

// Активные кампании дня — из боевой дневной статистики: форма знакома
// (ok), кампании с показами есть.
func TestActiveCampaignsFromRealDaily(t *testing.T) {
	active, ok := ActiveCampaigns(ozonfixture.Read(t, "performance/statistics-daily-2026-08-25.json"))

	require.True(t, ok)
	assert.NotEmpty(t, active)
	assert.True(t, active["16017246"])
}
