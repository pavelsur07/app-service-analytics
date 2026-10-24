package executor

import (
	"context"
	"log/slog"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
)

// StocksWindowStart — начало окна снимка остатков после полуночи МСК
// (docs/tasks/marketplace-data-ingestion/stages/ozon-5-stocks.md §3).
var StocksWindowStart = map[string]time.Duration{
	ResourceStocks: 5 * time.Hour,
}

// IsStocksResource — ресурс снимков остатков.
func IsStocksResource(resourceType string) bool {
	_, ok := StocksWindowStart[resourceType]
	return ok
}

// stocks — снимок остатков по cursor до пустой страницы, только в окне снимка
// дня. Журнал — число товаров и SKU страниц этой попытки для сверки с Symfony.
func (r *taskRun) stocks(ctx context.Context, creds ozon.Credentials) error {
	if r.cursor == "" && len(r.hashes) > 0 {
		// Все страницы уже сохранены прежней попыткой.
		return nil
	}
	items, total, pages := 0, int64(-1), 0
	skus := map[int64]bool{}
	err := r.withinSnapshot(ctx, StocksWindowStart[r.task.ResourceType], func(ctx context.Context) error {
		return r.exec.ozon.FetchStocks(ctx, r.task.Account, creds, r.cursor, r.nextPage, func(p ozon.Page) error {
			if page, err := ozon.ParseStocksPage(p.Body); err == nil {
				pages++
				items += page.Items
				for _, sku := range page.SKUs {
					skus[sku] = true
				}
				// total — с непустых страниц; пустой снимок — с концевой.
				if page.Items > 0 || total < 0 {
					total = page.Total
				}
			}
			return r.store(ctx, p)
		})
	})
	if err != nil {
		return err
	}
	r.log.Info("stocks snapshot read", slog.String("stage", "fetch"), slog.Int("pages", pages),
		slog.Int("items", items), slog.Int("skus", len(skus)), slog.Int64("total", total),
		slog.Bool("continued", r.task.Cursor != ""))
	if total >= 0 && r.task.Cursor == "" && int64(items) != total {
		// Снимок мог измениться во время обхода — повтор зациклился бы у активного продавца.
		r.log.Warn("stocks snapshot: items differ from total", slog.String("stage", "fetch"),
			slog.Int("items", items), slog.Int64("total", total))
	}
	return nil
}
