package config

import (
	"strings"
	"testing"
	"time"
)

func TestLoadDefaults(t *testing.T) {
	t.Setenv("LISTEN_ADDR", "")
	t.Setenv("DB_DSN", "")

	cfg, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if cfg.ListenAddr != ":8080" {
		t.Errorf("ListenAddr = %q, want :8080", cfg.ListenAddr)
	}
	if cfg.DBDSN != "" {
		t.Errorf("DBDSN = %q, want empty", cfg.DBDSN)
	}
}

func TestLoadFromEnv(t *testing.T) {
	t.Setenv("LISTEN_ADDR", ":9999")
	t.Setenv("DB_DSN", "postgres://svc:secret@db:5432/ozon_fetcher")

	cfg, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if cfg.ListenAddr != ":9999" {
		t.Errorf("ListenAddr = %q, want :9999", cfg.ListenAddr)
	}
	if cfg.DBDSN == "" {
		t.Error("DBDSN is empty, want value from env")
	}
}

func TestLoadConnectionsSettings(t *testing.T) {
	tests := []struct {
		name     string
		baseURL  string
		token    string
		interval string
		wantErr  bool
	}{
		{name: "disabled without base url", baseURL: "", token: ""},
		{name: "enabled", baseURL: "http://site-nginx", token: "0123456789abcdef0123456789abcdef", interval: "30s"},
		{name: "short token", baseURL: "http://site-nginx", token: "short", wantErr: true},
		{name: "no token", baseURL: "http://site-nginx", wantErr: true},
		{name: "not a url", baseURL: "site-nginx", token: "0123456789abcdef0123456789abcdef", wantErr: true},
		{name: "bad interval", interval: "soon", wantErr: true},
		{name: "zero interval", interval: "0s", wantErr: true},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			t.Setenv("SYMFONY_INTERNAL_BASE_URL", tt.baseURL)
			t.Setenv("INTERNAL_AUTH_TOKEN", tt.token)
			t.Setenv("CONNECTIONS_POLL_INTERVAL", tt.interval)
			t.Setenv("DB_DSN", "postgres://svc@db:5432/ozon_fetcher")
			for k, v := range map[string]string{"S3_ENDPOINT": "https://s3.example", "S3_REGION": "ru-1", "S3_BUCKET": "b", "S3_ACCESS_KEY_ID": "id", "S3_SECRET_ACCESS_KEY": "secret"} {
				t.Setenv(k, v)
			}

			cfg, err := Load()
			if tt.wantErr {
				if err == nil {
					t.Fatal("want error")
				}
				if tt.token != "" && strings.Contains(err.Error(), tt.token) {
					t.Fatal("token leaked into error")
				}
				return
			}
			if err != nil {
				t.Fatal(err)
			}
			if tt.interval == "" && cfg.ConnectionsPollInterval != 5*time.Minute {
				t.Errorf("default interval = %v", cfg.ConnectionsPollInterval)
			}
		})
	}
}

func TestLoadS3Settings(t *testing.T) {
	full := map[string]string{
		"S3_ENDPOINT": "https://s3.twcstorage.ru", "S3_REGION": "ru-1", "S3_BUCKET": "ozon-fetcher-raw",
		"S3_ACCESS_KEY_ID": "key-id", "S3_SECRET_ACCESS_KEY": "secret-value",
	}
	tests := []struct {
		name    string
		drop    string
		empty   bool
		wantErr bool
	}{
		{name: "disabled", empty: true},
		{name: "complete"},
		{name: "no endpoint", drop: "S3_ENDPOINT", wantErr: true},
		{name: "no region", drop: "S3_REGION", wantErr: true},
		{name: "no secret", drop: "S3_SECRET_ACCESS_KEY", wantErr: true},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			for k, v := range full {
				if tt.empty || k == tt.drop {
					v = ""
				}
				t.Setenv(k, v)
			}
			t.Setenv("SYMFONY_INTERNAL_BASE_URL", "")
			t.Setenv("CONNECTIONS_POLL_INTERVAL", "")
			cfg, err := Load()
			if tt.wantErr {
				if err == nil || strings.Contains(err.Error(), "secret-value") {
					t.Fatalf("want error without secret, got %v", err)
				}
				return
			}
			if err != nil {
				t.Fatal(err)
			}
			if !tt.empty && cfg.S3Bucket != "ozon-fetcher-raw" {
				t.Errorf("bucket = %q", cfg.S3Bucket)
			}
		})
	}
}

func TestLoadMaxParallel(t *testing.T) {
	for raw, want := range map[string]int{"": 4, "1": 1, "64": 64, "0": -1, "65": -1, "many": -1} {
		t.Run("value "+raw, func(t *testing.T) {
			t.Setenv("MAX_PARALLEL_CONNECTIONS", raw)
			t.Setenv("SYMFONY_INTERNAL_BASE_URL", "")
			t.Setenv("CONNECTIONS_POLL_INTERVAL", "")
			t.Setenv("S3_BUCKET", "")
			cfg, err := Load()
			if want < 0 {
				if err == nil {
					t.Fatal("want error")
				}
				return
			}
			if err != nil {
				t.Fatal(err)
			}
			if cfg.MaxParallelConnections != want {
				t.Errorf("got %d, want %d", cfg.MaxParallelConnections, want)
			}
		})
	}
}

func TestLoadPlannerAndIngestionSettings(t *testing.T) {
	clear := func(t *testing.T) {
		for _, k := range []string{"ORDERS_FROM", "ORDERS_TAIL_DAYS", "ACCRUAL_POSTINGS_DAYS", "ACCRUAL_POSTINGS_DAILY_DAYS", "OZON_PERFORMANCE_BASE_URL", "TAIL_DAYS", "FINAL_DAY", "RECONCILE_WEEKDAY", "PLAN_EVERY", "OZON_BASE_URL", "SYMFONY_INTERNAL_BASE_URL", "INTERNAL_AUTH_TOKEN", "S3_BUCKET", "DB_DSN", "CONNECTIONS_POLL_INTERVAL", "MAX_PARALLEL_CONNECTIONS"} {
			t.Setenv(k, "")
		}
	}
	t.Run("defaults", func(t *testing.T) {
		clear(t)
		cfg, err := Load()
		if err != nil {
			t.Fatal(err)
		}
		if cfg.TailDays != 3 || cfg.FinalDay != 10 || cfg.ReconcileWeekday != time.Monday || cfg.PlanEvery != time.Hour {
			t.Errorf("defaults = %+v", cfg)
		}
		if cfg.AccrualPostingsDays != 45 || cfg.AccrualPostingsDailyDays != 14 {
			t.Errorf("accrual postings days = %d/%d", cfg.AccrualPostingsDays, cfg.AccrualPostingsDailyDays)
		}
		if cfg.OrdersFrom != "" || cfg.OrdersTailDays != 30 {
			t.Errorf("orders defaults = %q %d", cfg.OrdersFrom, cfg.OrdersTailDays)
		}
		if cfg.OzonBaseURL != "https://api-seller.ozon.ru" || cfg.IngestionEnabled() {
			t.Errorf("ozon/ingestion = %q %v", cfg.OzonBaseURL, cfg.IngestionEnabled())
		}
	})
	for name, env := range map[string][2]string{
		"tail too big":      {"TAIL_DAYS", "40"},
		"final day 31":      {"FINAL_DAY", "31"},
		"weekday 7":         {"RECONCILE_WEEKDAY", "7"},
		"plan every short":  {"PLAN_EVERY", "10s"},
		"ozon url":          {"OZON_BASE_URL", "api-seller.ozon.ru"},
		"perf url":          {"OZON_PERFORMANCE_BASE_URL", "api-performance.ozon.ru"},
		"orders from bad":   {"ORDERS_FROM", "18.10.2026"},
		"orders from next":  {"ORDERS_FROM", time.Now().AddDate(0, 0, 2).Format(time.DateOnly)},
		"orders tail big":   {"ORDERS_TAIL_DAYS", "61"},
		"orders tail zero":  {"ORDERS_TAIL_DAYS", "0"},
		"accrual days big":  {"ACCRUAL_POSTINGS_DAYS", "91"},
		"accrual days 0":    {"ACCRUAL_POSTINGS_DAYS", "0"},
		"accrual daily 0":   {"ACCRUAL_POSTINGS_DAILY_DAYS", "0"},
		"accrual daily big": {"ACCRUAL_POSTINGS_DAILY_DAYS", "91"},
	} {
		t.Run(name, func(t *testing.T) {
			clear(t)
			t.Setenv(env[0], env[1])
			if _, err := Load(); err == nil {
				t.Fatal("want error")
			}
		})
	}
	t.Run("orders from today in Moscow", func(t *testing.T) {
		clear(t)
		loc, err := time.LoadLocation("Europe/Moscow")
		if err != nil {
			t.Fatal(err)
		}
		t.Setenv("ORDERS_FROM", time.Now().In(loc).Format(time.DateOnly))
		if _, err := Load(); err != nil {
			t.Fatalf("today (MSK) must be accepted: %v", err)
		}
	})
	t.Run("orders from env", func(t *testing.T) {
		clear(t)
		t.Setenv("ORDERS_FROM", "2026-10-01")
		t.Setenv("ORDERS_TAIL_DAYS", "1")
		cfg, err := Load()
		if err != nil {
			t.Fatal(err)
		}
		if cfg.OrdersFrom != "2026-10-01" || cfg.OrdersTailDays != 1 {
			t.Errorf("orders = %q %d", cfg.OrdersFrom, cfg.OrdersTailDays)
		}
	})
	t.Run("ingestion needs db and s3", func(t *testing.T) {
		clear(t)
		t.Setenv("SYMFONY_INTERNAL_BASE_URL", "http://site-nginx")
		t.Setenv("INTERNAL_AUTH_TOKEN", "0123456789abcdef0123456789abcdef")
		if _, err := Load(); err == nil {
			t.Fatal("want error without DB_DSN and S3_BUCKET")
		}
	})
}

func TestLoadConnectionAllowlist(t *testing.T) {
	t.Setenv("SYMFONY_INTERNAL_BASE_URL", "")
	t.Setenv("CONNECTION_ALLOWLIST", " ref-a, ,company-b ")
	cfg, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(cfg.ConnectionAllowlist, "|") != "ref-a|company-b" {
		t.Errorf("allowlist = %q", cfg.ConnectionAllowlist)
	}
}

func TestLoadServiceAPIToken(t *testing.T) {
	t.Setenv("SERVICE_API_TOKEN", "")
	cfg, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if cfg.ServiceAPIToken != "" {
		t.Errorf("ServiceAPIToken = %q, want empty", cfg.ServiceAPIToken)
	}

	t.Setenv("SERVICE_API_TOKEN", "short")
	if _, err := Load(); err == nil || !strings.Contains(err.Error(), "SERVICE_API_TOKEN") {
		t.Errorf("short token: err = %v, want SERVICE_API_TOKEN error", err)
	}

	long := strings.Repeat("s", MinInternalTokenLength)
	t.Setenv("SERVICE_API_TOKEN", long)
	cfg, err = Load()
	if err != nil {
		t.Fatal(err)
	}
	if cfg.ServiceAPIToken != long {
		t.Error("ServiceAPIToken is not taken from env")
	}
}
