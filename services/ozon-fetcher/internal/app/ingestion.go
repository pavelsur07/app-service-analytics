// Package app собирает цепочку загрузки: кабинеты → планировщик →
// диспетчер → исполнитель (docs/tasks/ozon-fetcher-ingestion/stages/stage-11.md).
package app

import (
	"context"
	"fmt"
	"log/slog"
	"math/rand/v2"
	"net/http"
	"os"
	"sync"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/config"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/dispatcher"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/executor"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozonperf"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/planner"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ratelimit"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/rawstore"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

// Ingestion — запущенная цепочка; Wait возвращается после остановки всех частей.
type Ingestion struct {
	Cabinets *connections.Cache
	Tasks    *store.Tasks
	Raw      *rawstore.Store
	Location *time.Location
	wg       sync.WaitGroup
}

func (i *Ingestion) Wait() { i.wg.Wait() }

// Start поднимает загрузку; остановка — отменой ctx (задачи в работе
// возвращаются в очередь исполнителем).
func Start(ctx context.Context, cfg config.Config, pool *store.Pool, logger *slog.Logger) (*Ingestion, error) {
	loc, err := time.LoadLocation("Europe/Moscow")
	if err != nil {
		return nil, fmt.Errorf("app: load Europe/Moscow: %w", err)
	}
	tasks := store.NewTasks(pool)
	now := time.Now

	cabinets := connections.NewCache(
		connections.NewClient(cfg.SymfonyBaseURL, cfg.InternalAuthToken, &http.Client{Timeout: 30 * time.Second}),
		logger, now).WithAllowlist(cfg.ConnectionAllowlist).WithMaxStale(cfg.ConnectionsMaxStale)

	limits := ratelimit.New(ratelimit.DefaultConfig(), now, ozon.SleepContext, func(ref account.Ref, endpoint string, rps float64) {
		go func() {
			saveCtx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 5*time.Second)
			defer cancel()
			if err := tasks.SaveCurrentRate(saveCtx, ref, endpoint, rps); err != nil {
				logger.Warn("rate limit save failed", slog.String("stage", "ratelimit"), slog.String("company_id", ref.CompanyID), slog.String("marketplace_account_id", ref.AccountID), slog.String("error", err.Error()))
			}
		}()
	})
	// Шторм 429: скорость кабинета на минимуме, а Ozon продолжает отказывать —
	// одна ошибка в GlitchTip (не чаще раза в час на пару кабинет + метод).
	limits.WithStormAlert(ratelimit.DefaultStormAlert(func(s ratelimit.Storm) {
		logger.Error("ozon rate limit storm: cabinet at minimum rate and still throttled",
			slog.String("stage", "ratelimit"), slog.String("error_class", "rate_limit_storm"),
			slog.String("company_id", s.Account.CompanyID), slog.String("marketplace_account_id", s.Account.AccountID), slog.String("endpoint", s.Endpoint),
			slog.Int("rate_limits_at_floor", s.Hits), slog.Int("window_minutes", int(s.Window.Minutes())),
			slog.Float64("rps", s.Floor))
	}))
	saved, err := tasks.LoadLimitsAllConnections(ctx)
	if err != nil {
		return nil, err
	}
	for _, l := range saved {
		limits.Seed(l.Account, l.Endpoint, l.Override, l.Current)
	}

	client := ozon.NewClient(cfg.OzonBaseURL, &http.Client{Timeout: ozon.DefaultTimeout}, logger, now).WithLimiter(limits)
	s3Client := rawstore.NewS3Client(cfg.S3Endpoint, cfg.S3Region, cfg.S3AccessKeyID, cfg.S3SecretAccessKey)
	raw := rawstore.New(s3Client, cfg.S3Bucket, cfg.MaxParallelConnections*2)
	execCfg := executor.DefaultConfig()
	execCfg.Location = loc
	perf := ozonperf.NewClient(cfg.OzonPerformanceBaseURL, &http.Client{Timeout: ozonperf.DefaultTimeout}, logger, now).WithLimiter(limits)
	exec := executor.New(execCfg, tasks, raw, client, cabinets, logger, now, jitter, ozon.SleepContext).
		WithRawReader(rawstore.NewReader(s3Client, cfg.S3Bucket)).
		WithPerformance(perf)
	// Отзыв доходит до идущих задач (ADR-043 п. 4): после каждого обновления
	// снимка и при его устаревании исполнитель сверяет их с выдачей.
	cabinets.OnChange(exec.Reconcile)

	// Пустой ORDERS_FROM — с M-2, как первичная загрузка начислений.
	var ordersFrom time.Time
	if cfg.OrdersFrom != "" {
		var err error
		if ordersFrom, err = time.ParseInLocation(time.DateOnly, cfg.OrdersFrom, loc); err != nil {
			return nil, fmt.Errorf("app: ORDERS_FROM: %w", err)
		}
	}
	plannerCfg := planner.Config{Location: loc, Resources: planner.FirstWave, TailDays: cfg.TailDays, ReconcileWeekday: cfg.ReconcileWeekday, FinalDay: cfg.FinalDay,
		OrdersFrom: ordersFrom, OrdersTailDays: cfg.OrdersTailDays, AccrualPostingsDays: cfg.AccrualPostingsDays,
		AccrualPostingsDailyDays: cfg.AccrualPostingsDailyDays}
	scheduler := planner.NewScheduler(plannerCfg, cfg.PlanEvery, tasks, cabinets, logger, now)

	hostname, _ := os.Hostname()
	disp := dispatcher.New(dispatcher.Config{
		MaxParallel:  cfg.MaxParallelConnections,
		Lease:        execCfg.Lease,
		IdlePoll:     5 * time.Second,
		ReleaseEvery: 30 * time.Second,
		Owner:        fmt.Sprintf("%s:%d", hostname, os.Getpid()),
	}, tasks, cabinets, exec, logger, now, nil)

	ing := &Ingestion{Cabinets: cabinets, Tasks: tasks, Raw: raw, Location: loc}
	for _, run := range []func(context.Context){
		func(c context.Context) { cabinets.Run(c, cfg.ConnectionsPollInterval) },
		scheduler.Run,
		disp.Run,
	} {
		ing.wg.Add(1)
		go func() {
			defer ing.wg.Done()
			run(ctx)
		}()
	}
	logger.Info("ingestion started", slog.String("stage", "start"), slog.Int("max_parallel", cfg.MaxParallelConnections),
		slog.Int("allowlist", len(cfg.ConnectionAllowlist)), slog.Duration("connections_max_stale", cfg.ConnectionsMaxStale))
	return ing, nil
}

func jitter(d time.Duration) time.Duration {
	if d <= 0 {
		return 0
	}
	return rand.N(d) //nolint:gosec // G404: разброс повторов, не криптография
}
