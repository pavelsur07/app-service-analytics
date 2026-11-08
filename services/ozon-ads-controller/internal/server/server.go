package server

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"net/http"
)

type Readiness interface{ Ready(context.Context) error }

func Handler(db Readiness, token string, enabled bool) http.Handler {
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
	return mux
}
