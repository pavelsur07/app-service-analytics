// Package dispatcher — параллельная загрузка кабинетов: до N задач
// одновременно, по одной на кабинет (гарантия БД, store.ClaimNextAcrossConnections).
package dispatcher

import (
	"context"
	"errors"
	"log/slog"
	"sync"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/executor"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/uuidv7"
)

// Claimer — часть store.Tasks для диспетчера.
type Claimer interface {
	ClaimNextAcrossConnections(ctx context.Context, refs []account.Ref, owner, attemptID, traceID string, now time.Time, lease time.Duration) (store.Task, error)
	ReleaseExpiredAcrossConnections(ctx context.Context, now time.Time) (int64, error)
}

// Cabinets — снимок кабинетов (connections.Cache).
type Cabinets interface {
	Ready() bool
	List() []connections.Cabinet
	Get(ref account.Ref) (connections.Cabinet, bool)
}

// Runner — исполнитель одной задачи (executor.Executor).
type Runner interface {
	Run(ctx context.Context, task store.Task, cabinet connections.Cabinet, traceID string) executor.Result
}

// Config — параметры диспетчера.
type Config struct {
	MaxParallel  int
	Lease        time.Duration // как у исполнителя
	IdlePoll     time.Duration // пауза, когда задач нет
	ReleaseEvery time.Duration
	Owner        string // hostname:pid
}

type Dispatcher struct {
	cfg      Config
	claimer  Claimer
	cabinets Cabinets
	runner   Runner
	logger   *slog.Logger
	now      func() time.Time
	newID    func() string
}

func New(cfg Config, claimer Claimer, cabinets Cabinets, runner Runner, logger *slog.Logger, now func() time.Time, newID func() string) *Dispatcher {
	if cfg.MaxParallel < 1 {
		cfg.MaxParallel = 1
	}
	if newID == nil {
		newID = uuidv7.Now
	}
	return &Dispatcher{cfg: cfg, claimer: claimer, cabinets: cabinets, runner: runner, logger: logger, now: now, newID: newID}
}

// Run — до отмены ctx; возвращается, когда все воркеры завершились.
func (d *Dispatcher) Run(ctx context.Context) {
	var wg sync.WaitGroup
	slots := make(chan struct{}, d.cfg.MaxParallel)
	freed := make(chan struct{}, 1) // пробуждение при освобождении слота

	wg.Add(1)
	go func() {
		defer wg.Done()
		d.releaseLoop(ctx)
	}()

	defer wg.Wait()
	for {
		select {
		case <-ctx.Done():
			return
		case slots <- struct{}{}:
		}

		task, cabinet, traceID, ok := d.claim(ctx)
		if !ok {
			<-slots
			select {
			case <-ctx.Done():
				return
			case <-freed:
			case <-time.After(d.cfg.IdlePoll):
			}
			continue
		}

		wg.Add(1)
		go func() {
			defer wg.Done()
			defer func() {
				<-slots
				select {
				case freed <- struct{}{}:
				default:
				}
			}()
			d.runner.Run(ctx, task, cabinet, traceID)
		}()
	}
}

// claim — следующая задача; false — задач нет, снимка нет или ошибка БД.
func (d *Dispatcher) claim(ctx context.Context) (store.Task, connections.Cabinet, string, bool) {
	if !d.cabinets.Ready() {
		return store.Task{}, connections.Cabinet{}, "", false
	}
	list := d.cabinets.List()
	refs := make([]account.Ref, 0, len(list))
	for _, cab := range list {
		refs = append(refs, cab.Account)
	}

	traceID := d.newID()
	task, err := d.claimer.ClaimNextAcrossConnections(ctx, refs, d.cfg.Owner, d.newID(), traceID, d.now(), d.cfg.Lease)
	switch {
	case errors.Is(err, store.ErrNoTask):
		return store.Task{}, connections.Cabinet{}, "", false
	case err != nil:
		if ctx.Err() == nil {
			d.logger.Warn("dispatcher: claim failed", slog.String("stage", "dispatch"), slog.String("error", err.Error()))
		}
		return store.Task{}, connections.Cabinet{}, "", false
	}
	cabinet, _ := d.cabinets.Get(task.Account) // исчез — исполнитель отложит задачу
	return task, cabinet, traceID, true
}

func (d *Dispatcher) releaseLoop(ctx context.Context) {
	ticker := time.NewTicker(d.cfg.ReleaseEvery)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			n, err := d.claimer.ReleaseExpiredAcrossConnections(ctx, d.now())
			switch {
			case err != nil && ctx.Err() == nil:
				d.logger.Warn("dispatcher: release expired failed", slog.String("stage", "dispatch"), slog.String("error", err.Error()))
			case n > 0:
				d.logger.Warn("dispatcher: expired leases released", slog.String("stage", "dispatch"), slog.Int64("released", n))
			}
		}
	}
}
