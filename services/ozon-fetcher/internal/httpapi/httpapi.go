// Package httpapi — HTTP сервиса: /health, /ready, /internal/fetch-status,
// /internal/coverage. Наружу сервис не публикуется; /internal/* — по токену
// направления «Symfony → сервис» (SERVICE_API_TOKEN).
package httpapi

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"log/slog"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

// Deps — источники состояния; nil — часть выключена (режим scaffold).
type Deps struct {
	// Token — SERVICE_API_TOKEN; пусто — /internal/* закрыт.
	Token         string
	DBPing        func(ctx context.Context) error
	CabinetsReady func() bool
	S3Ping        func(ctx context.Context) error
	Status        func(ctx context.Context, now time.Time, windowFrom string) (store.StatusReport, error)
	Cabinets      func() []connections.Cabinet
	Coverage      func(ctx context.Context, refs []account.Ref, from, to string) ([]store.CoverageRow, error)
	Location      *time.Location
	Now           func() time.Time
	Logger        *slog.Logger
}

// S3PingCache — результат проверки S3 держится 30 с: /ready дёргают часто.
const S3PingCache = 30 * time.Second

func New(d Deps) http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /health", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
	})

	s3 := &cachedPing{ping: d.S3Ping, now: d.Now}
	mux.HandleFunc("GET /ready", func(w http.ResponseWriter, r *http.Request) {
		ctx, cancel := context.WithTimeout(r.Context(), 2*time.Second)
		defer cancel()
		body, ok := map[string]string{"status": "ready"}, true
		check := func(name string, enabled bool, err func() error) {
			switch {
			case !enabled:
				body[name] = "disabled"
			case err() != nil:
				body[name], ok = "down", false
			default:
				body[name] = "up"
			}
		}
		check("db", d.DBPing != nil, func() error { return d.DBPing(ctx) })
		check("s3", d.S3Ping != nil, func() error { return s3.check(ctx) })
		if d.CabinetsReady != nil {
			if d.CabinetsReady() {
				body["cabinets"] = "up"
			} else {
				body["cabinets"], ok = "waiting", false
			}
		}
		status := http.StatusOK
		if !ok {
			body["status"], status = "not ready", http.StatusServiceUnavailable
		}
		writeJSON(w, status, body)
	})

	mux.HandleFunc("GET /internal/fetch-status", func(w http.ResponseWriter, r *http.Request) {
		if !requireToken(w, r, d.Token) {
			return
		}
		if d.Status == nil {
			writeJSON(w, http.StatusServiceUnavailable, map[string]any{"error": map[string]string{"code": "ingestion_disabled", "message": "loading is disabled"}})
			return
		}
		now := d.Now()
		local := now.In(d.Location)
		windowFrom := time.Date(local.Year(), local.Month(), 1, 0, 0, 0, 0, d.Location).AddDate(0, -2, 0).Format(time.DateOnly)
		report, err := d.Status(r.Context(), now, windowFrom)
		if err != nil {
			d.Logger.Warn("fetch status failed", slog.String("stage", "status"), slog.String("error", err.Error()))
			writeJSON(w, http.StatusServiceUnavailable, map[string]any{"error": map[string]string{"code": "status_unavailable", "message": "status is unavailable"}})
			return
		}
		writeJSON(w, http.StatusOK, map[string]any{"generated_at": now.Format(time.RFC3339), "window_from": windowFrom, "report": report})
	})

	mux.HandleFunc("GET /internal/coverage", func(w http.ResponseWriter, r *http.Request) {
		if requireToken(w, r, d.Token) {
			coverage(w, r, d)
		}
	})
	return mux
}

func requireToken(w http.ResponseWriter, r *http.Request, token string) bool {
	if authorized(r, token) {
		return true
	}
	w.Header().Set("WWW-Authenticate", "Bearer")
	writeError(w, http.StatusUnauthorized, "internal_auth_required", "internal token required")
	return false
}

func writeError(w http.ResponseWriter, code int, errCode, message string) {
	writeJSON(w, code, map[string]any{"error": map[string]string{"code": errCode, "message": message}})
}

func authorized(r *http.Request, token string) bool {
	if len(token) == 0 {
		return false
	}
	got, ok := strings.CutPrefix(r.Header.Get("Authorization"), "Bearer ")
	return ok && subtle.ConstantTimeCompare([]byte(got), []byte(token)) == 1
}

type cachedPing struct {
	ping func(ctx context.Context) error
	now  func() time.Time

	mu      sync.Mutex
	checked time.Time
	last    error
}

func (c *cachedPing) check(ctx context.Context) error {
	c.mu.Lock()
	defer c.mu.Unlock()
	if !c.checked.IsZero() && c.now().Sub(c.checked) < S3PingCache {
		return c.last
	}
	c.last, c.checked = c.ping(ctx), c.now()
	return c.last
}

func writeJSON(w http.ResponseWriter, code int, payload any) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(payload)
}
