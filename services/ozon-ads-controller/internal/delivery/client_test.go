package delivery

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/observation"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/store"
	"github.com/santhosh-tekuri/jsonschema/v6"
)

func corpus(t *testing.T) []struct {
	Name, Definition string
	Valid            bool
	Payload          json.RawMessage
} {
	t.Helper()
	raw, err := os.ReadFile("../../../_contracts/ozon-ads-observe/v1/examples.json")
	if err != nil {
		t.Fatal(err)
	}
	var cases []struct {
		Name, Definition string
		Valid            bool
		Payload          json.RawMessage
	}
	if err = json.Unmarshal(raw, &cases); err != nil {
		t.Fatal(err)
	}
	return cases
}
func TestContractCorpus(t *testing.T) {
	raw, err := os.ReadFile("../../../_contracts/ozon-ads-observe/v1/schema.json")
	if err != nil {
		t.Fatal(err)
	}
	for _, c := range corpus(t) {
		var document map[string]any
		if err = json.Unmarshal(raw, &document); err != nil {
			t.Fatal(err)
		}
		document["oneOf"] = []any{map[string]any{"$ref": "#/$defs/" + c.Definition}}
		compiler := jsonschema.NewCompiler()
		compiler.AssertFormat()
		if err = compiler.AddResource("schema.json", document); err != nil {
			t.Fatal(err)
		}
		schema, compileErr := compiler.Compile("schema.json")
		if compileErr != nil {
			t.Fatal(compileErr)
		}
		var payload any
		if err = json.Unmarshal(c.Payload, &payload); err != nil {
			t.Fatal(err)
		}
		if valid := schema.Validate(payload) == nil; valid != c.Valid {
			t.Fatal(c.Name, valid)
		}
	}
}
func TestCompleteRefreshAndPublish(t *testing.T) {
	now := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	policyPage := corpus(t)[0].Payload
	requests := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests++
		if r.Header.Get("Authorization") != "Bearer service-to-php" {
			t.Error("token direction")
		}
		w.Header().Set("Content-Type", "application/json")
		switch r.URL.Path {
		case "/api/internal/ozon-ads/policies":
			_, _ = w.Write(policyPage)
		case "/api/internal/marketplace-connections":
			_, _ = w.Write([]byte(`{"schema_version":"1","generated_at":"2026-11-08T10:00:00Z","connections":[{"company_id":"019a60df-1000-7000-8000-000000000001","marketplace_account_id":"019a60df-1000-7000-8000-000000000002","provider":"ozon","updated_at":"2026-11-08T09:00:00Z","resource_types":["ozon.ads.campaigns"],"credentials":{"seller":null,"performance":{"client_id":"dummy-id","client_secret":"dummy-key"}}}],"next_cursor":null}`))
		case "/api/internal/ozon-ads/observations":
			_, _ = w.Write([]byte(`{"schema_version":"1","accepted":true}`))
		default:
			w.WriteHeader(404)
		}
	}))
	defer srv.Close()
	client := Client{HTTP: srv.Client(), Base: srv.URL, Token: "service-to-php"}
	config, err := client.Fetch(context.Background(), now)
	if err != nil || len(config.Policies) != 1 || len(config.Credentials) != 1 {
		t.Fatal(config, err)
	}
	p := config.Policies[0]
	if config.Credentials[AccountKey(p.CompanyID, p.AccountID)].ClientSecret != "dummy-key" {
		t.Fatal("scope mismatch")
	}
	if err = client.Publish(context.Background(), p.CompanyID, p.AccountID, p.ID, store.Runtime{AppliedVersion: 1}); err != nil || requests != 3 {
		t.Fatal(err, requests)
	}
}
func TestPartialAndIncompatibleRefreshRejected(t *testing.T) {
	for _, body := range []string{`{"schema_version":"2","policies":[],"next_cursor":null}`, `{"schema_version":"1","policies":null,"next_cursor":null}`, `{"schema_version":"1","policies":[],"next_cursor":"repeat"}`} {
		srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { _, _ = w.Write([]byte(body)) }))
		client := Client{HTTP: srv.Client(), Base: srv.URL}
		_, err := client.Fetch(context.Background(), time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC))
		srv.Close()
		if err == nil {
			t.Fatal("invalid refresh accepted", body)
		}
	}
}
func TestPolicyRuntimeRoundTrip(t *testing.T) {
	for _, c := range corpus(t) {
		if !c.Valid {
			continue
		}
		if c.Definition == "Configuration" {
			var page struct {
				Policies []observation.Policy `json:"policies"`
			}
			if err := json.Unmarshal(c.Payload, &page); err != nil || page.Policies[0].Validate() != nil {
				t.Fatal(err)
			}
		} else {
			var envelope struct {
				Runtime store.Runtime `json:"runtime"`
			}
			if err := json.Unmarshal(c.Payload, &envelope); err != nil {
				t.Fatal(err)
			}
			raw, err := json.Marshal(envelope.Runtime)
			if err != nil || strings.Contains(string(raw), "client_secret") {
				t.Fatal(err)
			}
		}
	}
}

func TestConnectionsRejectMissingNullableAndMetadataFields(t *testing.T) {
	base := `{"schema_version":"1","generated_at":"2026-11-08T10:00:00Z","connections":[{"company_id":"019a60df-1000-7000-8000-000000000001","marketplace_account_id":"019a60df-1000-7000-8000-000000000002","provider":"ozon","updated_at":"2026-11-08T09:00:00Z","resource_types":[],"credentials":{"seller":null,"performance":{"client_id":"id","client_secret":"key"}}}],"next_cursor":null}`
	if _, err := parseConnections([]byte(base)); err != nil {
		t.Fatal(err)
	}
	for _, broken := range []string{strings.Replace(base, `"generated_at":"2026-11-08T10:00:00Z",`, "", 1), strings.Replace(base, `"seller":null,`, "", 1), strings.Replace(base, `"resource_types":[],`, "", 1), strings.Replace(base, `"updated_at":"2026-11-08T09:00:00Z",`, "", 1), strings.Replace(base, `"schema_version"`, `"Schema_Version"`, 1), strings.Replace(base, `"next_cursor":null`, `"next_cursor":null,"unknown":true`, 1)} {
		if _, err := parseConnections([]byte(broken)); err == nil {
			t.Fatal("incomplete contract accepted")
		}
	}
}

func TestLatePageGrantIsClampedInsteadOfRejected(t *testing.T) {
	now := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	page := strings.Replace(string(corpus(t)[0].Payload), "2026-11-08T10:02:00Z", "2026-11-08T10:02:03Z", 1)
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.Contains(r.URL.Path, "marketplace-connections") {
			_, _ = w.Write([]byte(`{"schema_version":"1","generated_at":"2026-11-08T10:00:00Z","connections":[],"next_cursor":null}`))
			return
		}
		_, _ = w.Write([]byte(page))
	}))
	defer srv.Close()
	client := Client{HTTP: srv.Client(), Base: srv.URL}
	config, err := client.Fetch(context.Background(), now)
	if err != nil || len(config.Policies) != 1 || !config.Policies[0].PermissionUntil.Equal(now.Add(120*time.Second)) {
		t.Fatal(config, err)
	}
}

func TestInvalidPolicyCursorIsRejectedBeforeSecondPage(t *testing.T) {
	calls := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		calls++
		_, _ = w.Write([]byte(`{"schema_version":"1","policies":[],"next_cursor":"bad"}`))
	}))
	defer srv.Close()
	client := Client{HTTP: srv.Client(), Base: srv.URL}
	if _, err := client.Fetch(context.Background(), time.Now()); err == nil || calls != 1 {
		t.Fatal("invalid cursor accepted", calls, err)
	}
}
