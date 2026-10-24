package planner

import (
	"context"
	"log/slog"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

// TaskStore — постановка задач.
type TaskStore interface {
	Upsert(ctx context.Context, specs []store.TaskSpec) error
	PlanOnce(ctx context.Context, ref account.Ref, kind, runDate string, specs []store.TaskSpec) (bool, error)
}

// Cabinets — снимок кабинетов.
type Cabinets interface {
	Ready() bool
	List() []connections.Cabinet
}

// Scheduler — проход планировщика сразу и раз в every.
type Scheduler struct {
	cfg      Config
	every    time.Duration
	tasks    TaskStore
	cabinets Cabinets
	logger   *slog.Logger
	now      func() time.Time
}

func NewScheduler(cfg Config, every time.Duration, tasks TaskStore, cabinets Cabinets, logger *slog.Logger, now func() time.Time) *Scheduler {
	return &Scheduler{cfg: cfg, every: every, tasks: tasks, cabinets: cabinets, logger: logger, now: now}
}

// NotReadyRetry — повтор прохода, пока нет снимка кабинетов: на старте
// снимок приходит через секунды, ждать полный интервал нельзя.
const NotReadyRetry = 5 * time.Second

func (s *Scheduler) Run(ctx context.Context) {
	for {
		wait := s.every
		if !s.Tick(ctx) {
			wait = min(NotReadyRetry, s.every)
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(wait):
		}
	}
}

// Tick — один проход; false — снимка кабинетов ещё нет, ничего не поставлено.
func (s *Scheduler) Tick(ctx context.Context) bool {
	if !s.cabinets.Ready() {
		return false
	}
	started := s.now()
	plan := Build(started, s.cabinets.List(), s.cfg)

	if err := s.tasks.Upsert(ctx, plan.Ensure); err != nil {
		if ctx.Err() == nil {
			s.logger.Warn("planner: ensure failed", slog.String("stage", "plan"), slog.String("error", err.Error()))
		}
		return true
	}
	var refreshed []string
	for _, r := range plan.Refreshes {
		done, err := s.tasks.PlanOnce(ctx, r.Account, r.Kind, r.RunDate, r.Specs)
		if err != nil {
			if ctx.Err() == nil {
				s.logger.Warn("planner: refresh failed", slog.String("stage", "plan"), slog.String("kind", r.Kind), slog.String("error", err.Error()))
			}
			continue
		}
		if done {
			refreshed = append(refreshed, r.Kind+"/"+r.Account.String())
		}
	}
	s.logger.Info("planner pass",
		slog.String("stage", "plan"), slog.String("outcome", "ok"),
		slog.Int("ensure_tasks", len(plan.Ensure)), slog.Any("refreshed", refreshed),
		slog.Int64("duration_ms", s.now().Sub(started).Milliseconds()))
	return true
}
