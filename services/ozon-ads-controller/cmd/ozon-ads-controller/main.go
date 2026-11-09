package main

import (
	"context"
	"errors"
	"flag"

	"net/http"
	"os"
	"os/signal"
	"sync"
	"syscall"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/config"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/control"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/delivery"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/observability"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/server"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/store"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/uuidv7"
)

func main() {
	quotaPath := flag.String("quota-check", "", "offline validation of a quota passport; no database or network")
	migrate := flag.Bool("migrate", false, "apply observe schema")
	check := flag.Bool("migrations-check", false, "verify migrations without applying them")
	flag.Parse()
	if quotaCheckRequested(flag.CommandLine) {
		os.Exit(checkQuota(*quotaPath, time.Now(), os.Stdout))
	}
	log := observability.New(os.Stdout)
	ctx, cancel := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer cancel()
	if *migrate {
		if err := store.Migrate(ctx, os.Getenv("ADS_DB_DSN"), false); err != nil {
			log.Error("migration failed", "error_class", "database")
			os.Exit(1)
		}
		return
	}
	if *check {
		pending, checkErr := store.MigrationsPending(ctx, os.Getenv("ADS_DB_DSN"))
		if checkErr != nil || pending {
			log.Error("migrations are pending", "error_class", "database")
			os.Exit(1)
		}
		return
	}
	cfg, err := config.Load()
	if err != nil {
		log.Error("invalid configuration", "error_class", "config")
		os.Exit(1)
	}
	pending, checkErr := store.MigrationsPending(ctx, cfg.DSN)
	if checkErr != nil || pending {
		log.Error("migrations are pending", "error_class", "database")
		os.Exit(1)
	}
	db, err := store.Open(ctx, cfg.DSN)
	if err != nil {
		log.Error("database unavailable", "error_class", "database")
		os.Exit(1)
	}
	defer db.Close()
	quota := control.GuardQuota(db, time.Now, time.Now)
	reader, err := ozon.New(&http.Client{}, "https://api-performance.ozon.ru", quota, time.Now)
	if err != nil {
		log.Error("reader unavailable")
		os.Exit(1)
	}
	php := delivery.Client{HTTP: &http.Client{}, Base: cfg.PHPBase, Token: cfg.OutboundToken}
	engine := &control.Engine{Store: db, Reader: reader, Now: time.Now, ClockOK: quota.Check}
	var workers sync.WaitGroup
	if cfg.Enabled {
		workers.Go(func() {
			ticker := time.NewTicker(60 * time.Second)
			defer ticker.Stop()
			for {
				now := time.Now()
				refresh, stop := context.WithTimeout(ctx, 30*time.Second)
				snapshot, fetchErr := php.Fetch(refresh, now)
				if fetchErr == nil {
					fetchErr = engine.Install(refresh, snapshot, now)
				}
				stop()
				if fetchErr != nil {
					log.Warn("configuration refresh failed", "error_class", "delivery", "outcome", "blocked")
				}
				select {
				case <-ctx.Done():
					return
				case <-ticker.C:
				}
			}
		})
		workers.Go(func() {
			runErr := control.Workers(ctx, db, engine, cfg.Workers, time.Now, time.Now, uuidv7.New, func(claim *store.Claim, err error, duration time.Duration) {
				if err == nil {
					return
				}
				cycleLog := log
				if claim != nil {
					cycleLog = log.With("company_id", claim.CompanyID, "marketplace_account_id", claim.AccountID, "job_id", claim.Token)
				}
				errorClass := "cycle"
				if errors.Is(err, control.ErrClockSkew) {
					errorClass = "clock_skew"
				}
				cycleLog.Warn("observation cycle failed", "error_class", errorClass, "outcome", "blocked", "duration_ms", duration.Milliseconds())
			})
			if runErr != nil {
				log.Error("workers unavailable", "error_class", "config")
				cancel()
			}
		})
		workers.Go(func() {
			ticker := time.NewTicker(time.Second)
			defer ticker.Stop()
			for {
				select {
				case <-ctx.Done():
					return
				case <-ticker.C:
				}
				batch, loadErr := db.Pending(ctx, 100)
				if loadErr != nil {
					continue
				}
				for _, item := range batch {
					job, stop := context.WithTimeout(ctx, 10*time.Second)
					publishErr := php.Publish(job, item.CompanyID, item.AccountID, item.PolicyID, item.Runtime)
					if publishErr == nil {
						publishErr = db.Published(job, item.CompanyID, item.AccountID, item.PolicyID, item.Runtime.EvaluatedAt)
					}
					stop()
					if publishErr != nil {
						break
					}
				}
			}
		})
	}
	srv := &http.Server{Addr: cfg.Listen, Handler: server.Handler(db, cfg.InboundToken, cfg.Enabled, db, time.Now), ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 10 * time.Second, WriteTimeout: 10 * time.Second, IdleTimeout: 60 * time.Second}
	workers.Go(func() {
		<-ctx.Done()
		shutdown, stop := context.WithTimeout(context.Background(), 15*time.Second)
		defer stop()
		if err := srv.Shutdown(shutdown); err != nil {
			log.Warn("server shutdown incomplete")
		}
	})
	if err = srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		log.Error("http server failed")
		cancel()
	}
	workers.Wait()
}
