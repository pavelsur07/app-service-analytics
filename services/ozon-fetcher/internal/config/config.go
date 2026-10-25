// Package config — env-only конфигурация сервиса, проверка при старте (fail fast).
package config

import (
	"errors"
	"fmt"
	"net/url"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
)

// MinInternalTokenLength — как InternalAccessPolicy::MIN_TOKEN_LENGTH в Symfony.
const MinInternalTokenLength = 32

const (
	defaultPollInterval = 5 * time.Minute
	// defaultMaxStale — шесть интервалов поллинга (ADR-043 п. 4).
	defaultMaxStale    = 30 * time.Minute
	defaultMaxParallel = 4
	maxMaxParallel     = 64
)

type Config struct {
	ListenAddr string
	DBDSN      string

	// SymfonyBaseURL пуст — получение кабинетов выключено (режим scaffold).
	SymfonyBaseURL    string
	InternalAuthToken string
	// ServiceAPIToken — токен направления «Symfony → сервис» для /internal/*
	// сервиса (service-gateway.md §2.9); пусто — /internal/* закрыт.
	ServiceAPIToken         string
	ConnectionsPollInterval time.Duration
	// ConnectionsMaxStale — сколько живёт снимок без успешного подтверждения
	// выдачей; дольше — все задачи стоят (ADR-043 п. 4).
	ConnectionsMaxStale time.Duration
	// MaxParallelConnections — сколько кабинетов загружается одновременно.
	MaxParallelConnections int
	// ConnectionAllowlist — только эти подключения (marketplace_account_id);
	// пусто — ни одного (ADR-043 п. 5: закрыт по умолчанию, значения «все» нет).
	ConnectionAllowlist []string

	// OzonBaseURL — Seller API (на стенде — мок).
	OzonBaseURL string
	// OzonPerformanceBaseURL — Performance API (реклама).
	OzonPerformanceBaseURL string
	// Планировщик (stage-11.md).
	TailDays         int
	ReconcileWeekday time.Weekday
	FinalDay         int
	PlanEvery        time.Duration
	// Отправления: первый день (YYYY-MM-DD, МСК; пусто — с M-2) и хвост перезагрузки.
	OrdersFrom     string
	OrdersTailDays int
	// Начисления по отправлениям: дней перед сегодня, перечитываемых; из них
	// первые AccrualPostingsDailyDays — каждые сутки, остальные — раз в неделю.
	AccrualPostingsDays      int
	AccrualPostingsDailyDays int

	// GlitchTip (Sentry-протокол): пустой DSN — ошибки только в лог.
	SentryDSN         string
	SentryRelease     string
	SentryEnvironment string

	// S3Bucket пуст — запись raw выключена (режим scaffold).
	S3Endpoint        string
	S3Region          string
	S3Bucket          string
	S3AccessKeyID     string
	S3SecretAccessKey string
}

func Load() (Config, error) {
	cfg := Config{
		ListenAddr:               os.Getenv("LISTEN_ADDR"),
		DBDSN:                    os.Getenv("DB_DSN"),
		SymfonyBaseURL:           os.Getenv("SYMFONY_INTERNAL_BASE_URL"),
		InternalAuthToken:        os.Getenv("INTERNAL_AUTH_TOKEN"),
		ServiceAPIToken:          os.Getenv("SERVICE_API_TOKEN"),
		ConnectionsPollInterval:  defaultPollInterval,
		ConnectionsMaxStale:      defaultMaxStale,
		MaxParallelConnections:   defaultMaxParallel,
		OzonBaseURL:              envOr("OZON_BASE_URL", "https://api-seller.ozon.ru"),
		OzonPerformanceBaseURL:   envOr("OZON_PERFORMANCE_BASE_URL", "https://api-performance.ozon.ru"),
		TailDays:                 3,
		ReconcileWeekday:         time.Monday,
		FinalDay:                 10,
		PlanEvery:                time.Hour,
		OrdersTailDays:           30,
		AccrualPostingsDays:      45,
		AccrualPostingsDailyDays: 14,
		SentryDSN:                os.Getenv("SENTRY_DSN"),
		SentryRelease:            os.Getenv("SENTRY_RELEASE"),
		SentryEnvironment:        envOr("SENTRY_ENVIRONMENT", "prod"),
		S3Endpoint:               os.Getenv("S3_ENDPOINT"),
		S3Region:                 os.Getenv("S3_REGION"),
		S3Bucket:                 os.Getenv("S3_BUCKET"),
		S3AccessKeyID:            os.Getenv("S3_ACCESS_KEY_ID"),
		S3SecretAccessKey:        os.Getenv("S3_SECRET_ACCESS_KEY"),
	}
	if cfg.ListenAddr == "" {
		cfg.ListenAddr = ":8080"
	}

	if raw := os.Getenv("CONNECTIONS_POLL_INTERVAL"); raw != "" {
		d, err := time.ParseDuration(raw)
		if err != nil || d <= 0 {
			return Config{}, fmt.Errorf("config: CONNECTIONS_POLL_INTERVAL must be a positive duration, got %q", raw)
		}
		cfg.ConnectionsPollInterval = d
	}

	if raw := os.Getenv("CONNECTIONS_MAX_STALE"); raw != "" {
		d, err := time.ParseDuration(raw)
		if err != nil || d <= 0 {
			return Config{}, fmt.Errorf("config: CONNECTIONS_MAX_STALE must be a positive duration, got %q", raw)
		}
		cfg.ConnectionsMaxStale = d
	}
	// Срок не короче интервала поллинга: иначе снимок устаревал бы между
	// двумя штатными обновлениями и задачи стояли бы без сбоя выдачи.
	if cfg.ConnectionsMaxStale <= cfg.ConnectionsPollInterval {
		return Config{}, fmt.Errorf("config: CONNECTIONS_MAX_STALE (%s) must exceed CONNECTIONS_POLL_INTERVAL (%s)", cfg.ConnectionsMaxStale, cfg.ConnectionsPollInterval)
	}

	if raw := os.Getenv("MAX_PARALLEL_CONNECTIONS"); raw != "" {
		n, err := strconv.Atoi(raw)
		if err != nil || n < 1 || n > maxMaxParallel {
			return Config{}, fmt.Errorf("config: MAX_PARALLEL_CONNECTIONS must be 1..%d, got %q", maxMaxParallel, raw)
		}
		cfg.MaxParallelConnections = n
	}

	// Подключения добавляются поимённо (ADR-043 п. 5): только
	// marketplace_account_id; опечатка — отказ запуска, а не молча
	// выпавшее подключение.
	for _, id := range strings.Split(os.Getenv("OZON_FETCHER_CONNECTION_ALLOWLIST"), ",") {
		if id = strings.TrimSpace(id); id == "" {
			continue
		}
		if !account.ValidID(id) {
			return Config{}, fmt.Errorf("config: OZON_FETCHER_CONNECTION_ALLOWLIST entries must be marketplace_account_id UUIDs, got %q", id)
		}
		cfg.ConnectionAllowlist = append(cfg.ConnectionAllowlist, id)
	}

	if err := loadPlanner(&cfg); err != nil {
		return Config{}, err
	}
	if u, err := url.Parse(cfg.OzonBaseURL); err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		return Config{}, errors.New("config: OZON_BASE_URL must be an http(s) URL")
	}
	if u, err := url.Parse(cfg.OzonPerformanceBaseURL); err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		return Config{}, errors.New("config: OZON_PERFORMANCE_BASE_URL must be an http(s) URL")
	}

	if cfg.SymfonyBaseURL != "" {
		u, err := url.Parse(cfg.SymfonyBaseURL)
		if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
			return Config{}, fmt.Errorf("config: SYMFONY_INTERNAL_BASE_URL must be an http(s) URL")
		}
		if len(cfg.InternalAuthToken) < MinInternalTokenLength {
			return Config{}, errors.New("config: INTERNAL_AUTH_TOKEN is required (at least 32 characters) with SYMFONY_INTERNAL_BASE_URL")
		}
	}
	if cfg.ServiceAPIToken != "" && len(cfg.ServiceAPIToken) < MinInternalTokenLength {
		return Config{}, errors.New("config: SERVICE_API_TOKEN must be at least 32 characters")
	}
	if cfg.S3Bucket != "" {
		u, err := url.Parse(cfg.S3Endpoint)
		if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
			return Config{}, errors.New("config: S3_ENDPOINT must be an http(s) URL with S3_BUCKET")
		}
		if cfg.S3Region == "" || cfg.S3AccessKeyID == "" || cfg.S3SecretAccessKey == "" {
			return Config{}, errors.New("config: S3_REGION, S3_ACCESS_KEY_ID and S3_SECRET_ACCESS_KEY are required with S3_BUCKET")
		}
	}
	if cfg.IngestionEnabled() && (cfg.DBDSN == "" || cfg.S3Bucket == "") {
		return Config{}, errors.New("config: loading needs DB_DSN and S3_BUCKET together with SYMFONY_INTERNAL_BASE_URL")
	}
	return cfg, nil
}

// IngestionEnabled — загрузка включена: есть источник кабинетов (а с ним
// обязательны БД и S3). Иначе сервис работает в режиме scaffold.
func (c Config) IngestionEnabled() bool { return c.SymfonyBaseURL != "" }

func loadPlanner(cfg *Config) error {
	ints := []struct {
		name     string
		min, max int
		dst      *int
	}{
		{"TAIL_DAYS", 0, 31, &cfg.TailDays},
		{"FINAL_DAY", 1, 28, &cfg.FinalDay},
		{"ORDERS_TAIL_DAYS", 1, 60, &cfg.OrdersTailDays},
		{"ACCRUAL_POSTINGS_DAYS", 1, 90, &cfg.AccrualPostingsDays},
		{"ACCRUAL_POSTINGS_DAILY_DAYS", 1, 90, &cfg.AccrualPostingsDailyDays},
	}
	for _, v := range ints {
		raw := os.Getenv(v.name)
		if raw == "" {
			continue
		}
		n, err := strconv.Atoi(raw)
		if err != nil || n < v.min || n > v.max {
			return fmt.Errorf("config: %s must be %d..%d, got %q", v.name, v.min, v.max, raw)
		}
		*v.dst = n
	}
	if raw := os.Getenv("RECONCILE_WEEKDAY"); raw != "" {
		n, err := strconv.Atoi(raw)
		if err != nil || n < 0 || n > 6 {
			return fmt.Errorf("config: RECONCILE_WEEKDAY must be 0..6 (0 = Sunday), got %q", raw)
		}
		cfg.ReconcileWeekday = time.Weekday(n)
	}
	if raw := os.Getenv("ORDERS_FROM"); raw != "" {
		// День МСК, как у планировщика: сравниваются календарные даты.
		loc, locErr := time.LoadLocation("Europe/Moscow")
		if locErr != nil {
			return fmt.Errorf("config: load Europe/Moscow: %w", locErr)
		}
		d, err := time.ParseInLocation(time.DateOnly, raw, loc)
		today := time.Now().In(loc).Format(time.DateOnly)
		if err != nil || d.Format(time.DateOnly) > today {
			return fmt.Errorf("config: ORDERS_FROM must be a past date YYYY-MM-DD, got %q", raw)
		}
		cfg.OrdersFrom = raw
	}
	if raw := os.Getenv("PLAN_EVERY"); raw != "" {
		d, err := time.ParseDuration(raw)
		if err != nil || d < time.Minute {
			return fmt.Errorf("config: PLAN_EVERY must be a duration of at least 1m, got %q", raw)
		}
		cfg.PlanEvery = d
	}
	return nil
}

func envOr(name, fallback string) string {
	if v := os.Getenv(name); v != "" {
		return v
	}
	return fallback
}
