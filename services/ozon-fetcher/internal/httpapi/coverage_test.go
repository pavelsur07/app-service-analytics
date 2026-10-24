package httpapi

import (
	"context"
	"errors"
	"net/http"
	"sort"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account/accounttest"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

const byDay = "ozon.finance.accrual-by-day"

type coverageCall struct {
	refs     []account.Ref
	from, to string
}

func coverageDeps(t *testing.T, call *coverageCall) Deps {
	d := deps(t)
	seller := &ozon.Credentials{ClientID: "1", APIKey: "k"}
	d.Cabinets = func() []connections.Cabinet {
		return []connections.Cabinet{
			{Account: accounttest.Ref("ref-a"), Seller: seller, ResourceTypes: []string{byDay, "ozon.finance.realization", byDay}},
			{Account: accounttest.Ref("ref-b"), Seller: seller, ResourceTypes: []string{byDay}},
			{Account: accounttest.Ref("ref-perf"), HasPerformance: true, ResourceTypes: []string{byDay, "ozon.ads.expense"}},
		}
	}
	success := time.Date(2026, 10, 16, 21, 5, 0, 0, time.UTC)
	d.Coverage = func(_ context.Context, refs []account.Ref, from, to string) ([]store.CoverageRow, error) {
		*call = coverageCall{refs: refs, from: from, to: to}
		return []store.CoverageRow{
			{ResourceType: byDay, PeriodKey: "2026-10-15", ByStatus: map[string]int{"done": 2, "running": 0, "pending": 0, "not_ready": 0, "failed_retryable": 0, "failed_permanent": 0}, LastSuccessAt: &success},
			{ResourceType: "ozon.finance.realization", PeriodKey: "2026-09", ByStatus: map[string]int{"done": 0, "running": 0, "pending": 1, "not_ready": 0, "failed_retryable": 0, "failed_permanent": 0}},
			{ResourceType: "ozon.ads.expense", PeriodKey: "2026-10-15", ByStatus: map[string]int{"done": 1, "running": 0, "pending": 0, "not_ready": 0, "failed_retryable": 0, "failed_permanent": 0}},
		}, nil
	}
	return d
}

func keys(m map[string]any) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}

func TestCoverageRequiresServiceToken(t *testing.T) {
	var call coverageCall
	h := New(coverageDeps(t, &call))
	const internalAuthToken = "symfony-direction-token-0123456789ab" //nolint:gosec // G101: фиктивный токен теста

	for _, auth := range []string{"", "Bearer " + internalAuthToken, "Basic " + token} {
		code, body := do(t, h, "/internal/coverage", auth)
		assert.Equal(t, http.StatusUnauthorized, code, auth)
		assert.Equal(t, "internal_auth_required", body["error"].(map[string]any)["code"])
	}
	assert.Empty(t, call.refs, "без токена в хранилище не ходим")

	closed := coverageDeps(t, &call)
	closed.Token = ""
	code, _ := do(t, New(closed), "/internal/coverage", "Bearer ")
	assert.Equal(t, http.StatusUnauthorized, code, "без SERVICE_API_TOKEN — закрыто")
}

func TestCoverageDefaultWindowAndContractShape(t *testing.T) {
	var call coverageCall
	code, body := do(t, New(coverageDeps(t, &call)), "/internal/coverage", "Bearer "+token)

	require.Equal(t, http.StatusOK, code)
	assert.Equal(t, coverageCall{refs: accounttest.Refs("ref-a", "ref-b", "ref-perf"), from: "2026-08-01", to: "2026-10-31"}, call, "окно M-2…конец M по МСК, кабинеты с ключом Seller или Performance")

	// Ключи — как в services/_contracts/service-coverage/v1/schema.json.
	assert.Equal(t, []string{"cabinets_total", "from", "generated_at", "rows", "schema_version", "service", "to"}, keys(body))
	assert.Equal(t, "1", body["schema_version"])
	assert.Equal(t, "ozon-fetcher", body["service"])
	assert.Equal(t, "2026-10-17T09:00:00Z", body["generated_at"])
	assert.InDelta(t, 2, body["cabinets_total"], 0)

	rows := body["rows"].([]any)
	require.Len(t, rows, 3)
	assert.InDelta(t, 1, rows[2].(map[string]any)["cabinets_expected"], 0, "реклама — у кабинета с ключом Performance")
	first, second := rows[0].(map[string]any), rows[1].(map[string]any)
	assert.Equal(t, []string{"by_status", "cabinets_expected", "last_success_at", "period_key", "resource_type"}, keys(first))
	assert.Equal(t, []string{"done", "failed_permanent", "failed_retryable", "not_ready", "pending", "running"}, keys(first["by_status"].(map[string]any)))
	assert.InDelta(t, 2, first["cabinets_expected"], 0, "дубль ресурса у кабинета не считается дважды")
	assert.Equal(t, "2026-10-16T21:05:00Z", first["last_success_at"])
	assert.InDelta(t, 1, second["cabinets_expected"], 0)
	assert.Nil(t, second["last_success_at"])
	assert.Contains(t, second, "last_success_at", "null, а не отсутствие ключа")
}

func TestCoverageExplicitWindow(t *testing.T) {
	var call coverageCall
	code, body := do(t, New(coverageDeps(t, &call)), "/internal/coverage?from=2026-10-01&to=2026-12-31", "Bearer "+token)
	require.Equal(t, http.StatusOK, code, "92 дня — можно")
	assert.Equal(t, "2026-10-01", call.from)
	assert.Equal(t, "2026-12-31", call.to)
	assert.Equal(t, "2026-10-01", body["from"])
}

func TestCoverageRejectsInvalidPeriod(t *testing.T) {
	var call coverageCall
	h := New(coverageDeps(t, &call))
	for _, q := range []string{
		"from=2026-10-1",
		"to=",
		"from=2026-13-01",
		"from=2026-10-02&to=2026-10-01",
		"from=2026-01-01&to=2026-04-04", // 94 дня
	} {
		code, body := do(t, h, "/internal/coverage?"+q, "Bearer "+token)
		assert.Equal(t, http.StatusUnprocessableEntity, code, q)
		assert.Equal(t, "invalid_period", body["error"].(map[string]any)["code"], q)
	}
	code, _ := do(t, h, "/internal/coverage?from=2026-01-01&to=2026-04-03", "Bearer "+token)
	assert.Equal(t, http.StatusOK, code, "93 дня включительно — граница")
}

func TestCoverageDisabledAndErrors(t *testing.T) {
	code, body := do(t, New(deps(t)), "/internal/coverage", "Bearer "+token)
	assert.Equal(t, http.StatusServiceUnavailable, code)
	assert.Equal(t, "ingestion_disabled", body["error"].(map[string]any)["code"])

	var call coverageCall
	d := coverageDeps(t, &call)
	d.Coverage = func(context.Context, []account.Ref, string, string) ([]store.CoverageRow, error) {
		return nil, errors.New("db down")
	}
	code, body = do(t, New(d), "/internal/coverage", "Bearer "+token)
	assert.Equal(t, http.StatusServiceUnavailable, code)
	assert.Equal(t, "coverage_unavailable", body["error"].(map[string]any)["code"])
	assert.NotContains(t, body["error"].(map[string]any)["message"], "db down")
}

func TestCoverageWaitsForCabinetSnapshot(t *testing.T) {
	var call coverageCall
	d := coverageDeps(t, &call)
	ready := false
	d.CabinetsReady = func() bool { return ready }

	code, body := do(t, New(d), "/internal/coverage", "Bearer "+token)
	assert.Equal(t, http.StatusServiceUnavailable, code)
	assert.Equal(t, "cabinets_not_ready", body["error"].(map[string]any)["code"])
	assert.Empty(t, call.from, "до снимка кабинетов в хранилище не ходим")

	ready = true
	code, _ = do(t, New(d), "/internal/coverage", "Bearer "+token)
	assert.Equal(t, http.StatusOK, code)
}
