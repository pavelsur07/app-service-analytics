package ozon

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

type quota struct {
	mu      sync.Mutex
	blocked map[string]time.Time
	used    atomic.Int64
	reject  bool
}

func (q *quota) Take(context.Context, string, string, time.Time) error {
	q.used.Add(1)
	if q.reject {
		return fmt.Errorf("quota_exhausted")
	}
	return nil
}
func credential(id string) Credentials {
	return Credentials{CompanyID: "company-" + id, AccountID: "account-" + id, ClientID: id, ClientSecret: "secret-" + id, UpdatedAt: time.Date(2026, 11, 8, 0, 0, 0, 0, time.UTC)}
}
func TestReadIsolationAndSingleFlight(t *testing.T) {
	var tokens atomic.Int64
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if r.URL.Path == "/api/client/token" {
			var pair struct {
				ID     string `json:"client_id"`
				Secret string `json:"client_secret"`
			}
			if json.NewDecoder(r.Body).Decode(&pair) != nil || pair.Secret != "secret-"+pair.ID {
				t.Error("credentials mixed")
			}
			tokens.Add(1)
			_, _ = fmt.Fprintf(w, `{"access_token":%q,"token_type":"Bearer","expires_in":3600}`, "token-"+pair.ID)
			return
		}
		if r.Header.Get("Authorization") != "Bearer token-a" && r.Header.Get("Authorization") != "Bearer token-b" {
			t.Error("wrong token")
		}
		_, _ = w.Write([]byte(`{"rows":[]}`))
	}))
	defer server.Close()
	q := &quota{}
	client, err := New(server.Client(), server.URL, q, time.Now)
	if err != nil {
		t.Fatal(err)
	}
	var wg sync.WaitGroup
	for range 15 {
		for _, id := range []string{"a", "b"} {
			wg.Go(func() {
				_, readErr := client.Aggregate(context.Background(), credential(id), "2026-11-08")
				if readErr != nil {
					t.Error(readErr)
				}
			})
		}
	}
	wg.Wait()
	if tokens.Load() != 2 || q.used.Load() != 32 {
		t.Fatal(tokens.Load(), q.used.Load())
	}
	if _, err = client.request(context.Background(), credential("a"), "GET", "/api/client/campaign/1/activate", "", nil); err == nil {
		t.Fatal("mutation allowed")
	}
	if _, err = client.request(context.Background(), credential("a"), "POST", "/api/client/campaign/1/deactivate", "", nil); err == nil {
		t.Fatal("mutation allowed")
	}
}
func TestFailuresAreBoundedAndDoNotExposeBody(t *testing.T) {
	for _, status := range []int{401, 403, 429, 500} {
		t.Run(fmt.Sprint(status), func(t *testing.T) {
			var reads, tokens atomic.Int64
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				w.Header().Set("Content-Type", "application/json")
				if r.URL.Path == "/api/client/token" {
					tokens.Add(1)
					_, _ = w.Write([]byte(`{"access_token":"test","token_type":"Bearer","expires_in":3600}`))
					return
				}
				reads.Add(1)
				w.Header().Set("Retry-After", "1200")
				w.WriteHeader(status)
				_, _ = w.Write([]byte("sensitive-body"))
			}))
			defer server.Close()
			client, err := New(server.Client(), server.URL, &quota{}, time.Now)
			if err != nil {
				t.Fatal(err)
			}
			_, err = client.Campaigns(context.Background(), credential("a"))
			if err == nil || strings.Contains(err.Error(), "sensitive") {
				t.Fatal(err)
			}
			want := int64(1)
			if status == 401 {
				want = 2
			}
			if reads.Load() != want || tokens.Load() != want {
				t.Fatal(reads.Load(), tokens.Load())
			}
			if status == 429 && err.(*ReplyError).RetryAfter != 20*time.Minute {
				t.Fatal(err)
			}
		})
	}
}
func TestRedirectAndContentType(t *testing.T) {
	var calls atomic.Int64
	target := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { calls.Add(1); w.WriteHeader(200) }))
	defer target.Close()
	source := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { http.Redirect(w, r, target.URL, http.StatusFound) }))
	defer source.Close()
	client, err := New(source.Client(), source.URL, &quota{}, time.Now)
	if err != nil {
		t.Fatal(err)
	}
	if _, err = client.Campaigns(context.Background(), credential("a")); err == nil || calls.Load() != 0 {
		t.Fatal("redirect followed")
	}
	if _, err = New(source.Client(), "http://api-performance.ozon.ru", &quota{}, time.Now); err == nil {
		t.Fatal("plaintext vendor accepted")
	}
}

func TestSKURequestUsesExplicitDateAndScope(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if r.URL.Path == "/api/client/token" {
			_, _ = w.Write([]byte(`{"access_token":"test","token_type":"Bearer","expires_in":3600}`))
			return
		}
		if r.Method != "POST" || r.URL.Path != "/api/client/statistics/products/sku" {
			t.Error("unexpected operation")
		}
		manifestRaw, readErr := os.ReadFile("/api/tests/Fixtures/Marketplace/ozon/performance/cost-control-2026-11-08/manifest.json")
		if readErr != nil {
			t.Error(readErr)
		}
		var manifest []struct {
			Path string `json:"path"`
			Body string `json:"request_body"`
		}
		if json.Unmarshal(manifestRaw, &manifest) != nil {
			t.Error("invalid fixture manifest")
		}
		var expected map[string]json.RawMessage
		for _, entry := range manifest {
			if entry.Path == r.URL.Path && strings.Contains(entry.Body, "2026-11-08") {
				if json.Unmarshal([]byte(entry.Body), &expected) != nil {
					t.Error("invalid fixture request")
				}
				break
			}
		}
		var actual map[string]json.RawMessage
		if json.NewDecoder(r.Body).Decode(&actual) != nil {
			t.Error("invalid request")
		}
		expectedJSON, _ := json.Marshal(expected)
		actualJSON, _ := json.Marshal(actual)
		if string(expectedJSON) != string(actualJSON) {
			t.Error("request differs from captured Ozon request")
		}
		raw, _ := json.Marshal(actual)
		var body struct {
			Campaigns []string `json:"campaignIds"`
			From      string   `json:"dateFrom"`
			To        string   `json:"dateTo"`
		}
		if json.Unmarshal(raw, &body) != nil || len(body.Campaigns) != 1 || body.Campaigns[0] != "49761882" || body.From != "2026-11-08" || body.To != body.From {
			t.Error("wrong date or scope")
		}
		_, _ = w.Write([]byte(`{"rows":[]}`))
	}))
	defer server.Close()
	client, err := New(server.Client(), server.URL, &quota{}, time.Now)
	if err != nil {
		t.Fatal(err)
	}
	if _, err = client.SKU(context.Background(), credential("a"), "2026-11-08", []string{"49761882"}); err != nil {
		t.Fatal(err)
	}
	client.Forget()
	if _, err = client.SKU(context.Background(), credential("a"), "bad", []string{"49761882"}); err == nil {
		t.Fatal("invalid date")
	}
	if _, err = client.SKU(context.Background(), credential("a"), "2026-11-08", nil); err == nil {
		t.Fatal("empty scope")
	}
}

func (q *quota) AuthorizationAllowed(_ context.Context, company, account string, at time.Time) error {
	q.mu.Lock()
	defer q.mu.Unlock()
	if until, ok := q.blocked[company+account]; ok && !at.After(until) {
		return fmt.Errorf("authorization_blocked")
	}
	return nil
}
func (q *quota) RejectAuthorization(_ context.Context, company, account string, at time.Time) error {
	q.mu.Lock()
	defer q.mu.Unlock()
	if q.blocked == nil {
		q.blocked = map[string]time.Time{}
	}
	q.blocked[company+account] = at
	return nil
}

func TestRepeated401PausesUntilNewCredentialRevision(t *testing.T) {
	var reads atomic.Int64
	var allowed atomic.Bool
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if r.URL.Path == "/api/client/token" {
			_, _ = w.Write([]byte(`{"access_token":"test","token_type":"Bearer","expires_in":3600}`))
			return
		}
		reads.Add(1)
		if !allowed.Load() {
			w.WriteHeader(401)
			return
		}
		_, _ = w.Write([]byte(`{"list":[]}`))
	}))
	defer srv.Close()
	q := &quota{}
	client, err := New(srv.Client(), srv.URL, q, time.Now)
	if err != nil {
		t.Fatal(err)
	}
	cred := credential("a")
	if _, err = client.Campaigns(context.Background(), cred); err == nil || reads.Load() != 2 {
		t.Fatal(err, reads.Load())
	}
	client.Forget()
	if _, err = client.Campaigns(context.Background(), cred); err == nil || reads.Load() != 2 {
		t.Fatal("retried rejected credentials", err)
	}
	allowed.Store(true)
	cred.UpdatedAt = cred.UpdatedAt.Add(time.Second)
	if _, err = client.Campaigns(context.Background(), cred); err != nil || reads.Load() != 3 {
		t.Fatal(err, reads.Load())
	}
}
