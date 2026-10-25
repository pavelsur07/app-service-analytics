package observability

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"

	"github.com/getsentry/sentry-go"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

type captured struct {
	mu     sync.Mutex
	events []*sentry.Event
}

func (c *captured) capture(e *sentry.Event) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.events = append(c.events, e)
}

func newLogger() (*slog.Logger, *bytes.Buffer, *captured) {
	out := &bytes.Buffer{}
	c := &captured{}
	return slog.New(NewHandler(slog.NewJSONHandler(out, nil), c.capture)).With(slog.String("service", Service)), out, c
}

func TestOnlyErrorsGoToGlitchTip(t *testing.T) {
	logger, out, c := newLogger()

	logger.Info("fetch task started")
	logger.Warn("ozon request", slog.String("outcome", "rate_limit"))
	logger.Error("ozon endpoint is obsolete, resource stopped for all cabinets",
		slog.String("stage", "fetch"), slog.String("company_id", "c-1"), slog.String("marketplace_account_id", "a-1"),
		slog.String("resource_type", "ozon.finance.accrual-by-day"), slog.String("error_class", "obsolete_endpoint"),
		slog.Int("code", 9))

	require.Len(t, c.events, 1)
	e := c.events[0]
	assert.Equal(t, sentry.LevelError, e.Level)
	assert.Equal(t, "ozon endpoint is obsolete, resource stopped for all cabinets", e.Message)
	assert.Equal(t, map[string]string{
		"service": Service, "stage": "fetch", "company_id": "c-1", "marketplace_account_id": "a-1",
		"resource_type": "ozon.finance.accrual-by-day", "error_class": "obsolete_endpoint",
	}, e.Tags)
	assert.EqualValues(t, 9, e.Contexts["log"]["code"])
	assert.Equal(t, []string{Service, e.Message, "fetch", "obsolete_endpoint"}, e.Fingerprint, "один issue на вид ошибки, не на кабинет")
	assert.Equal(t, 3, strings.Count(out.String(), "\n"), "в лог пишутся все записи")
}

func TestAttrsFromWithAndGroupsAreKept(t *testing.T) {
	logger, _, c := newLogger()

	logger.With(slog.String("job_id", "att-1"), slog.String("stage", "fetch")).
		WithGroup("req").Error("fetch task finish failed", slog.String("error", "db down"))

	require.Len(t, c.events, 1)
	e := c.events[0]
	assert.Equal(t, "att-1", e.Contexts["log"]["job_id"])
	assert.Equal(t, "fetch", e.Tags["stage"])
	assert.Equal(t, "db down", e.Contexts["log"]["req.error"])
}

func TestSetupWithoutDSNSendsNothing(t *testing.T) {
	out := &bytes.Buffer{}
	logger, flush, err := Setup(out, "", "sha", "prod")
	require.NoError(t, err)
	logger.Error("boom")
	flush()
	assert.Contains(t, out.String(), `"service":"ozon-fetcher"`)
}

func TestBrokenDSNKeepsServiceLogging(t *testing.T) {
	out := &bytes.Buffer{}
	logger, flush, err := Setup(out, "not a dsn", "", "")
	require.Error(t, err)
	require.NotNil(t, logger, "сбой телеметрии не останавливает сервис")
	logger.Error("still logged")
	flush()
	assert.Contains(t, out.String(), "still logged")
}

func TestHandleIsConcurrencySafe(t *testing.T) {
	logger, _, c := newLogger()
	var wg sync.WaitGroup
	for range 20 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			logger.Error("x", slog.String("stage", "fetch"))
		}()
	}
	wg.Wait()
	assert.Len(t, c.events, 20)
	_ = context.Background()
}

func TestSetupSendsErrorEventToGlitchTip(t *testing.T) {
	var mu sync.Mutex
	var bodies []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		b, _ := io.ReadAll(r.Body)
		mu.Lock()
		bodies = append(bodies, r.URL.Path+"\n"+string(b))
		mu.Unlock()
		w.WriteHeader(http.StatusOK)
	}))
	defer srv.Close()
	dsn := strings.Replace(srv.URL, "http://", "http://publickey@", 1) + "/42"

	logger, flush, err := Setup(&bytes.Buffer{}, dsn, "sha-1", "prod")
	require.NoError(t, err)
	logger.Warn("ozon request", slog.String("outcome", "rate_limit"))
	logger.Error("fetch task finish failed", slog.String("stage", "fetch"), slog.String("error_class", "store"))
	flush()

	mu.Lock()
	defer mu.Unlock()
	require.Len(t, bodies, 1, "WARN не отправляется, ERROR — одним событием")
	assert.Contains(t, bodies[0], "/api/42/envelope/")
	assert.Contains(t, bodies[0], "fetch task finish failed")
	assert.Contains(t, bodies[0], `"release":"sha-1"`)
	assert.Contains(t, bodies[0], `"environment":"prod"`)
	assert.Contains(t, bodies[0], `"service":"ozon-fetcher"`)
	assert.NotContains(t, bodies[0], "rate_limit")
}

func decodeLines(t *testing.T, out *bytes.Buffer) []map[string]any {
	t.Helper()
	var records []map[string]any
	for _, line := range strings.Split(strings.TrimSpace(out.String()), "\n") {
		var rec map[string]any
		require.NoError(t, json.Unmarshal([]byte(line), &rec), line)
		records = append(records, rec)
	}
	return records
}

func TestEveryRecordCarriesTheRequiredFields(t *testing.T) {
	out := &bytes.Buffer{}
	logger := slog.New(NewHandler(slog.NewJSONHandler(out, nil), nil)) // без With("service") и без GlitchTip

	logger.Info("service started")
	ctx := WithFields(context.Background(), slog.String("job_id", "j-1"), slog.String("company_id", "c-1"))
	logger.InfoContext(ctx, "ozon request", slog.String("outcome", "ok"), slog.String("company_id", "c-record"))

	records := decodeLines(t, out)
	require.Len(t, records, 2)
	for _, rec := range records {
		for _, key := range Required {
			assert.Contains(t, rec, key, "поле %s в каждой записи", key)
		}
		assert.Equal(t, Service, rec["service"])
	}
	assert.Nil(t, records[0]["job_id"], "неизвестное поле — null, не пропуск")
	assert.Equal(t, "j-1", records[1]["job_id"], "поле задачи из контекста")
	assert.Equal(t, "c-record", records[1]["company_id"], "поле записи приоритетнее контекста")
	assert.Equal(t, "ok", records[1]["outcome"])
	assert.Equal(t, 1, strings.Count(strings.Split(out.String(), "\n")[1], `"company_id"`), "поле не дублируется")
}

func TestRequiredFieldsAreNotDuplicatedWhenSetByWith(t *testing.T) {
	out := &bytes.Buffer{}
	logger := slog.New(NewHandler(slog.NewJSONHandler(out, nil), nil)).With(slog.String("service", Service), slog.String("trace_id", "t-with"))

	logger.InfoContext(WithFields(context.Background(), slog.String("trace_id", "t-ctx")), "fetch task started")

	line := out.String()
	assert.Equal(t, 1, strings.Count(line, `"trace_id"`))
	assert.Equal(t, 1, strings.Count(line, `"service"`))
	assert.Contains(t, line, `"trace_id":"t-with"`)
}

func TestUnsetRequiredFieldsDoNotBecomeGlitchTipTags(t *testing.T) {
	logger, _, c := newLogger()

	logger.ErrorContext(WithFields(context.Background(), slog.String("resource_type", "ozon.orders.fbo")), "fetch task finish failed", slog.String("error_class", "db"))

	require.Len(t, c.events, 1)
	e := c.events[0]
	assert.Equal(t, "ozon.orders.fbo", e.Tags["resource_type"], "поле из контекста — тег")
	assert.NotContains(t, e.Tags, "company_id")
	assert.NotContains(t, e.Contexts["log"], "job_id")
}
