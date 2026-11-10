package observability

import (
	"bytes"
	"encoding/json"
	"strings"
	"testing"
)

func TestRequiredFieldsAndExplicitOutcomeArePresentOnce(t *testing.T) {
	var output bytes.Buffer
	log := New(&output)
	log.Error("migration failed", "error_class", "database")
	log.With("job_id", "dummy").Warn("cycle failed", "error_class", "cycle", "outcome", "blocked")
	for _, line := range strings.Split(strings.TrimSpace(output.String()), "\n") {
		var record map[string]any
		if err := json.Unmarshal([]byte(line), &record); err != nil {
			t.Fatal(err)
		}
		for _, key := range []string{"service", "company_id", "marketplace_account_id", "job_id", "trace_id", "resource_type", "duration_ms", "outcome", "error_class"} {
			if strings.Count(line, `"`+key+`":`) != 1 {
				t.Fatal("duplicate required field", key)
			}
			if _, ok := record[key]; !ok {
				t.Fatal("missing field", key)
			}
		}
		if strings.Count(line, `"outcome":`) != 1 || strings.Count(line, `"error_class":`) != 1 {
			t.Fatal("duplicate classification", line)
		}
	}
}
