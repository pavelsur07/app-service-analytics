package planner

import (
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/executor"
)

func TestProductsSnapshotOnlyForTodayWithoutRefresh(t *testing.T) {
	cabs := []connections.Cabinet{seller("a", executor.ResourceProductsList, executor.ResourceProductsInfo)}
	// 00:05 МСК 22.10 — первый проход нового дня.
	p := Build(at("2026-10-22 00:05"), cabs, sourceConfig(msk))

	for _, r := range []string{executor.ResourceProductsList, executor.ResourceProductsInfo} {
		assert.Equal(t, []string{"2026-10-22"}, periods(p.Ensure, r), r)
		s := specFor(p.Ensure, r, "2026-10-22")
		require.NotNil(t, s, r)
		assert.False(t, s.Refresh, "снимок дня — один раз")
		assert.Equal(t, PriorityRoutine, s.Priority)
		if r == executor.ResourceProductsInfo {
			assert.Equal(t, at("2026-10-22 00:06"), s.RunAt, "подробности — после списка")
		}
		for _, rf := range p.Refreshes {
			assert.Empty(t, periods(rf.Specs, r), "каталог не перечитывается")
		}
	}
}

func TestProductsNotPlannedWithoutResource(t *testing.T) {
	p := Build(at("2026-10-22 12:00"), []connections.Cabinet{seller("a", allFinance...)}, sourceConfig(msk))
	assert.Empty(t, periods(p.Ensure, executor.ResourceProductsList))
	assert.Empty(t, periods(p.Ensure, executor.ResourceProductsInfo))

	onlyInfo := Build(at("2026-10-22 12:00"), []connections.Cabinet{seller("a", executor.ResourceProductsInfo)}, sourceConfig(msk))
	assert.Empty(t, periods(onlyInfo.Ensure, executor.ResourceProductsInfo), "подробности — только со списком")
}

func TestProductsDoNotChangeOtherPlans(t *testing.T) {
	base := []string{executor.ResourceOrdersFBO, executor.ResourceAccrualPostings}
	base = append(base, allFinance...)
	without := Build(at("2026-10-22 03:00"), []connections.Cabinet{seller("a", base...)}, sourceConfig(msk))
	with := Build(at("2026-10-22 03:00"), []connections.Cabinet{seller("a", append(base, executor.ResourceProductsList, executor.ResourceProductsInfo)...)}, sourceConfig(msk))

	assert.Len(t, with.Ensure, len(without.Ensure)+2)
	assert.Equal(t, without.Refreshes, with.Refreshes)
}

func TestStocksSnapshotTodayFrom0500(t *testing.T) {
	cabs := []connections.Cabinet{seller("a", executor.ResourceStocks)}

	for _, now := range []string{"2026-10-22 00:05", "2026-10-25 12:00", "2026-10-26 00:05"} {
		p := Build(at(now), cabs, sourceConfig(msk))
		day := now[:10]
		assert.Equal(t, []string{day}, periods(p.Ensure, executor.ResourceStocks), now)
		s := specFor(p.Ensure, executor.ResourceStocks, day)
		require.NotNil(t, s)
		assert.Equal(t, at(day+" 05:00"), s.RunAt, "и в воскресенье, и в понедельник — один снимок в 05:00")
		assert.False(t, s.Refresh)
		assert.Len(t, p.Ensure, 1, "отдельного снимка конца недели нет")
	}
}

func TestStocksRemovedWeekCloseFromStaleSnapshotIsIgnored(t *testing.T) {
	// Снимок кабинетов, закэшированный до снятия ресурса, ещё объявляет его.
	p := Build(at("2026-10-25 12:00"), []connections.Cabinet{seller("a", executor.ResourceStocks, "ozon.stocks.week-close")}, sourceConfig(msk))
	assert.Equal(t, []string{"2026-10-25"}, periods(p.Ensure, executor.ResourceStocks))
	assert.Empty(t, periods(p.Ensure, "ozon.stocks.week-close"))
}

func TestStocksNotPlannedWithoutResource(t *testing.T) {
	p := Build(at("2026-10-25 12:00"), []connections.Cabinet{seller("a", allFinance...)}, sourceConfig(msk))
	assert.Empty(t, periods(p.Ensure, executor.ResourceStocks))
}
