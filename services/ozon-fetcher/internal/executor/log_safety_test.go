package executor

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/observability"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
)

// bodyMarker — строка тела ответа Ozon вне текста ошибки: в журнал она
// попасть не должна. Текст ошибки (message конверта, до 200 символов,
// без присланных ключей) журнал пишет намеренно — ozon.APIError.Error().
const bodyMarker = "ozon-body-marker-4f1c"

// Этап 6 (ADR-043, services/AGENTS.md §8): полный прогон задачи через
// настоящий клиент Ozon и логгер сервиса. Ни одна запись журнала не
// содержит ключей и тела ответа — даже когда Ozon повторяет ключ в тексте
// ошибки; каждая несёт обязательные поля, и запросы клиента — поля задачи.
func TestTaskLogsCarryRequiredFieldsAndNeverKeysOrBodies(t *testing.T) {
	statuses := []int{http.StatusInternalServerError, http.StatusUnauthorized}
	for _, status := range statuses {
		t.Run(http.StatusText(status), func(t *testing.T) {
			srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				_, _ = io.Copy(io.Discard, r.Body)
				w.WriteHeader(status)
				// Ozon повторяет ключ в тексте ошибки и кладёт в тело данные — худший случай.
				//nolint:gosec // G705: тестовый сервер повторяет заголовок намеренно — так ведёт себя Ozon
				_, _ = io.WriteString(w, `{"code":16,"message":"denied for api-key `+r.Header.Get("Api-Key")+`","details":[{"value":"`+bodyMarker+`"}]}`)
			}))
			defer srv.Close()

			logs := &bytes.Buffer{}
			logger, flush, err := observability.Setup(logs, "", "test", "test")
			require.NoError(t, err)
			defer flush()
			client := ozon.NewClient(srv.URL, srv.Client(), logger, func() time.Time { return t0 })
			h := newHarness(nil)
			cfg := DefaultConfig()
			cfg.Lease = time.Minute
			exec := New(cfg, h.tasks, h.raw, client, h.refresher, logger,
				func() time.Time { return t0 }, func(time.Duration) time.Duration { return 0 }, ozon.SleepContext)

			res := exec.Run(context.Background(), task(ResourceAccrualByDay, "2026-10-15"), cabinet, "trace-1")
			require.NotEqual(t, "done", res.Outcome)

			out := logs.String()
			assert.NotContains(t, out, secretKey, "ключ подключения")
			assert.NotContains(t, out, bodyMarker, "тело ответа")
			assert.Contains(t, out, "denied for api-key [redacted]", "текст ошибки — без ключа")

			var requests int
			for _, line := range strings.Split(strings.TrimSpace(out), "\n") {
				var rec map[string]any
				require.NoError(t, json.Unmarshal([]byte(line), &rec), line)
				for _, key := range observability.Required {
					assert.Contains(t, rec, key, "%s: поле %s", rec["msg"], key)
				}
				if rec["msg"] == "ozon request" {
					requests++
					assert.Equal(t, "att-1", rec["job_id"], "запрос клиента знает попытку")
					assert.Equal(t, "trace-1", rec["trace_id"])
					assert.Equal(t, ResourceAccrualByDay, rec["resource_type"])
				}
			}
			assert.Positive(t, requests, "запросы к Ozon были и записаны")
		})
	}
}
