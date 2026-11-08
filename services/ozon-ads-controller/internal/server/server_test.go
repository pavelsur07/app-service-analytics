package server

import (
	"context"
	"fmt"
	"net/http/httptest"
	"testing"
)

type db struct{ fail bool }

func (d db) Ready(context.Context) error {
	if d.fail {
		return fmt.Errorf("database")
	}
	return nil
}
func TestHealthAndTokenDirection(t *testing.T) {
	for _, tc := range []struct {
		path, token string
		fail        bool
		want        int
	}{{"/health", "", false, 200}, {"/ready", "", true, 503}, {"/ready", "", false, 200}, {"/internal/status", "", false, 401}, {"/internal/status", "wrong-direction", false, 401}, {"/internal/status", "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb", false, 200}, {"/internal/activate", "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb", false, 404}} {
		r := httptest.NewRequestWithContext(context.Background(), "GET", tc.path, nil)
		r.Header.Set("Authorization", "Bearer "+tc.token)
		w := httptest.NewRecorder()
		Handler(db{tc.fail}, "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb", false).ServeHTTP(w, r)
		if w.Code != tc.want {
			t.Fatal(tc, w.Code)
		}
	}
}
