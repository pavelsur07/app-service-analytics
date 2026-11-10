package server

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"net/http"
	"strconv"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/store"
)

type Readiness interface{ Ready(context.Context) error }
type Inspector interface {
	Inspect(context.Context, string, int, time.Time) (store.InspectionPage, error)
}

func Handler(db Readiness, token string, enabled bool, inspector Inspector, now func() time.Time) http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /health", func(w http.ResponseWriter, _ *http.Request) { w.WriteHeader(200) })
	mux.HandleFunc("GET /ready", func(w http.ResponseWriter, r *http.Request) {
		if db.Ready(r.Context()) != nil {
			w.WriteHeader(503)
			return
		}
		w.WriteHeader(200)
	})
	mux.HandleFunc("GET /internal/status", func(w http.ResponseWriter, r *http.Request) {
		if len(token) < 32 || subtle.ConstantTimeCompare([]byte(r.Header.Get("Authorization")), []byte("Bearer "+token)) != 1 {
			w.WriteHeader(401)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Cache-Control", "no-store")
		_ = json.NewEncoder(w).Encode(struct {
			Mode       string `json:"mode"`
			Enabled    bool   `json:"enabled"`
			Protection bool   `json:"budget_protection_active"`
		}{"observe", enabled, false})
	})
	mux.HandleFunc("GET /internal/observations", func(w http.ResponseWriter, r *http.Request) {
		if len(token) < 32 || subtle.ConstantTimeCompare([]byte(r.Header.Get("Authorization")), []byte("Bearer "+token)) != 1 {
			w.WriteHeader(401)
			return
		}
		w.Header().Set("Cache-Control", "no-store")
		limit := 100
		if r.URL.Query().Has("limit") {
			parsed, err := strconv.Atoi(r.URL.Query().Get("limit"))
			if err != nil || parsed < 1 || parsed > 100 {
				w.WriteHeader(422)
				return
			}
			limit = parsed
		}
		cursor := r.URL.Query().Get("cursor")
		if len(cursor) > 160 {
			w.WriteHeader(422)
			return
		}
		// Cursor validation is independent of database availability.
		if !store.ValidInspectionCursor(cursor) {
			w.WriteHeader(422)
			return
		}
		if inspector == nil {
			w.WriteHeader(503)
			return
		}
		job, cancel := context.WithTimeout(r.Context(), 2*time.Second)
		defer cancel()
		page, err := inspector.Inspect(job, cursor, limit, now())
		if err != nil {
			w.WriteHeader(503)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(page)
	})
	return mux
}
