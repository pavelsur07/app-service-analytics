package server

import (
	"context"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/store"
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
		Handler(db{tc.fail}, "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb", false, nil, time.Now).ServeHTTP(w, r)
		if w.Code != tc.want {
			t.Fatal(tc, w.Code)
		}
	}
}

type inspections struct {
	fail   bool
	called *bool
}

func (i inspections) Inspect(ctx context.Context, _ string, _ int, _ time.Time) (store.InspectionPage, error) {
	*i.called = true
	if deadline, ok := ctx.Deadline(); !ok || time.Until(deadline) > 2*time.Second {
		return store.InspectionPage{}, fmt.Errorf("missing deadline")
	}
	if i.fail {
		return store.InspectionPage{}, fmt.Errorf("private-secret")
	}
	return store.InspectionPage{Items: []store.Inspection{{PolicyID: "policy", Signal: "deadline_missed"}}}, nil
}
func TestPerPolicyMonitoringHasAuthBoundsAndNoSensitiveErrors(t *testing.T) {
	for _, tc := range []struct {
		query, token string
		fail         bool
		want         int
		called       bool
	}{
		{"", "", false, 401, false}, {"?limit=0", "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb", false, 422, false},
		{"?limit=101", "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb", false, 422, false}, {"?cursor=bad", "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb", false, 422, false},
		{"", "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb", true, 503, true}, {"?limit=1", "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb", false, 200, true},
	} {
		called := false
		handler := Handler(db{}, "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb", true, inspections{tc.fail, &called}, time.Now)
		request := httptest.NewRequestWithContext(context.Background(), "GET", "/internal/observations"+tc.query, nil)
		request.Header.Set("Authorization", "Bearer "+tc.token)
		response := httptest.NewRecorder()
		handler.ServeHTTP(response, request)
		if response.Code != tc.want || called != tc.called || strings.Contains(response.Body.String(), "private-secret") {
			t.Fatal("invalid diagnostics response")
		}
		if response.Code == 200 && !strings.Contains(response.Body.String(), "deadline_missed") {
			t.Fatal("healthy heartbeat hid missed policy")
		}
	}
}
