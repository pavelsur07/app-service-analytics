package main

import (
	"bytes"
	"flag"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestQuotaCLIInvalidInputDoesNotRevealPathOrBody(t *testing.T) {
	path := filepath.Join(t.TempDir(), "sentinel-private-path")
	if err := os.WriteFile(path, []byte(`{"secret":"sentinel-private-body"}`), 0600); err != nil {
		t.Fatal(err)
	}
	for _, name := range []string{path, path + "-absent"} {
		var out bytes.Buffer
		if code := checkQuota(name, time.Now(), &out); code != 1 {
			t.Fatal(code)
		}
		if strings.Contains(out.String(), "sentinel") || out.String() != "{\"error\":\"invalid_quota_passport\"}\n" {
			t.Fatal("unsafe output")
		}
	}
}

func TestQuotaCLIReadyStillRequiresLiveAdmission(t *testing.T) {
	body := `{"version":1,"revision":"test-only","scope_id":"0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50","window_seconds":86400,"clock_skew_seconds":0,"limit":1000,"reserve":100,"valid_from":"2026-11-09T00:00:00Z","valid_until":"2026-11-10T00:00:00Z","scope_evidence":"sentinel-scope","reset_evidence":"sentinel-reset","bootstrap_evidence":"sentinel-bootstrap","consumers_confirmed":true,"shares":[{"consumer":"ads","requests":500},{"consumer":"php","requests":200},{"consumer":"fetcher","requests":200}],"accounts":[{"company_id":"0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a51","account_id":"0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a52","poll_seconds":600,"cycle_requests":3,"oauth_max":48,"retry_max":10,"other_max":7}]}`
	path := filepath.Join(t.TempDir(), "passport.json")
	at := time.Date(2026, 11, 9, 12, 0, 0, 0, time.UTC)
	for _, tc := range []struct {
		body string
		code int
	}{{body, 0}, {strings.Replace(body, `"consumers_confirmed":true`, `"consumers_confirmed":false`, 1), 2}} {
		if err := os.WriteFile(path, []byte(tc.body), 0600); err != nil {
			t.Fatal(err)
		}
		var out bytes.Buffer
		if code := checkQuota(path, at, &out); code != tc.code {
			t.Fatal(code, out.String())
		}
		if !strings.Contains(out.String(), `"requires_live_admission":true`) || strings.Contains(out.String(), "sentinel") || strings.Contains(out.String(), "0199a1b2") {
			t.Fatal("unsafe admission output")
		}
	}
}

func TestExplicitEmptyQuotaFlagStaysOffline(t *testing.T) {
	for _, args := range [][]string{nil, {"-quota-check", ""}, {"-quota-check", "passport.json"}} {
		flags := flag.NewFlagSet("test", flag.ContinueOnError)
		path := flags.String("quota-check", "", "offline")
		if err := flags.Parse(args); err != nil {
			t.Fatal(err)
		}
		if quotaCheckRequested(flags) != (len(args) > 0) {
			t.Fatal("incorrect flag presence")
		}
		if len(args) > 0 && *path == "" {
			var out bytes.Buffer
			if checkQuota(*path, time.Now(), &out) != 1 {
				t.Fatal("empty path accepted")
			}
		}
	}
}
