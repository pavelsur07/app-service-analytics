package main

import (
	"context"
	"errors"
	"flag"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"
	_ "time/tzdata" // Europe/Moscow без zoneinfo в рантайм-образе

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/app"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/config"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/httpapi"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/observability"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

func main() {
	flush := func() {}
	err := run(func(f func()) { flush = f })
	if err != nil {
		slog.Error("ozon-fetcher stopped with error", "error", err)
	}
	flush() // события GlitchTip — до выхода процесса
	if err != nil {
		os.Exit(1)
	}
}

func run(setFlush func(func())) error {
	// До конфигурации — тот же общий обработчик, без GlitchTip: ошибка
	// config.Load тоже запись журнала с обязательными полями (§8).
	slog.SetDefault(slog.New(observability.NewHandler(slog.NewJSONHandler(os.Stdout, nil), nil)).With(slog.String("service", observability.Service)))
	migrate := flag.Bool("migrate", false, "apply database migrations and exit")
	migrationsCheck := flag.Bool("migrations-check", false, "exit with an error if service database migrations are pending (deploy gate) and exit")
	resumeHalt := flag.Bool("resume-halt", false, "lift the halt set on a possible Ozon access restriction (ADR-043 p. 5) and exit")
	flag.Parse()

	cfg, err := config.Load()
	if err != nil {
		return err
	}
	logger, flush, sentryErr := observability.Setup(os.Stdout, cfg.SentryDSN, cfg.SentryRelease, cfg.SentryEnvironment)
	slog.SetDefault(logger)
	setFlush(flush)
	if sentryErr != nil {
		// Сбой телеметрии не останавливает загрузку (services/AGENTS.md §6).
		slog.Error("glitchtip disabled: bad SENTRY_DSN", "error", sentryErr.Error())
	}

	if *migrate {
		if cfg.DBDSN == "" {
			return errors.New("migrate: DB_DSN is required")
		}
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()
		return store.Migrate(ctx, cfg.DBDSN)
	}
	if *migrationsCheck {
		return checkMigrations(cfg.DBDSN)
	}
	if *resumeHalt {
		return resumeAllConnections(cfg.DBDSN)
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	var pool *store.Pool
	if cfg.DBDSN != "" {
		p, err := store.Open(ctx, cfg.DBDSN, store.PoolSize(cfg.MaxParallelConnections))
		if err != nil {
			return err
		}
		defer p.Close()
		pool = p
		slog.Info("database connected")
	} else {
		slog.Info("database disabled: DB_DSN is empty")
	}

	// Загрузка — отдельным контекстом: при сигнале она останавливается первой
	// (задачи возвращаются в очередь), HTTP — после неё.
	ingestCtx, stopIngest := context.WithCancel(context.Background())
	defer stopIngest()
	var ingestion *app.Ingestion
	if cfg.IngestionEnabled() {
		ingestion, err = app.Start(ingestCtx, cfg, pool, slog.Default())
		if err != nil {
			return err
		}
	} else {
		slog.Info("ingestion disabled: SYMFONY_INTERNAL_BASE_URL is empty")
	}

	deps := httpapi.Deps{Token: cfg.ServiceAPIToken, Now: time.Now, Logger: slog.Default(), Location: time.UTC}
	if pool != nil {
		deps.DBPing = pool.Ping
	}
	if ingestion != nil {
		deps.CabinetsReady = ingestion.Cabinets.Ready
		deps.S3Ping = ingestion.Raw.Ping
		deps.Status = ingestion.Tasks.StatusAllConnections
		deps.Cabinets = ingestion.Cabinets.List
		deps.Coverage = ingestion.Tasks.CoverageAcrossConnections
		deps.Location = ingestion.Location
	}

	server := &http.Server{
		Addr:              cfg.ListenAddr,
		Handler:           httpapi.New(deps),
		ReadHeaderTimeout: 5 * time.Second,
	}

	errCh := make(chan error, 1)
	go func() {
		slog.Info("ozon-fetcher listening", "addr", cfg.ListenAddr)
		if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			errCh <- err
		}
	}()

	select {
	case <-ctx.Done():
		stopIngest()
		if ingestion != nil {
			ingestion.Wait()
		}
		shutdownCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		return server.Shutdown(shutdownCtx)
	case err := <-errCh:
		return err
	}
}

// resumeAllConnections снимает остановку загрузки по признаку ограничения
// доступа Ozon (ADR-043 п. 5). Только ручной запуск — человек или агент
// после разбора причины; сервис сам остановку не снимает.
func resumeAllConnections(dsn string) error {
	if dsn == "" {
		return errors.New("resume-halt: DB_DSN is required")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	pool, err := store.Open(ctx, dsn, store.PoolSize(1))
	if err != nil {
		return err
	}
	defer pool.Close()
	resumed, err := store.NewTasks(pool).ResumeAllConnections(ctx)
	if err != nil {
		return err
	}
	slog.Info("resume-halt", slog.Bool("resumed", resumed))
	return nil
}

// checkMigrations — гейт выкладки (services/AGENTS.md §5): ошибка, если
// миграции сервисной БД не применены; выкладка останавливается до запуска
// контейнера, агент применяет -migrate отдельным шагом.
func checkMigrations(dsn string) error {
	if dsn == "" {
		return errors.New("migrations-check: DB_DSN is required")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	pending, err := store.MigrationsPending(ctx, dsn)
	if err != nil {
		return err
	}
	if pending {
		return errors.New("migrations-check: service database migrations are pending, apply ozon-fetcher -migrate")
	}
	slog.Info("migrations-check: service database is up to date", slog.String("stage", "migrate"))
	return nil
}
