package planner

import (
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account/accounttest"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/executor"
)

var adsResources = []string{executor.ResourceAdsCampaigns, executor.ResourceAdsExpense, executor.ResourceAdsDaily}

func perfOnly(ref string, resources ...string) connections.Cabinet {
	return connections.Cabinet{Account: accounttest.Ref(ref), HasPerformance: true, ResourceTypes: resources}
}

func TestAdsPlanCampaignsTodayDaysFromAdsFromAndTail(t *testing.T) {
	p := Build(at("2026-10-22 00:05"), []connections.Cabinet{perfOnly("a", adsResources...)}, sourceConfig(msk))

	assert.Equal(t, []string{"2026-10-22"}, periods(p.Ensure, executor.ResourceAdsCampaigns))
	c := specFor(p.Ensure, executor.ResourceAdsCampaigns, "2026-10-22")
	require.NotNil(t, c)
	assert.Equal(t, at("2026-10-22 01:00"), c.RunAt)
	assert.False(t, c.Refresh)

	for _, r := range []string{executor.ResourceAdsExpense, executor.ResourceAdsDaily} {
		days := periods(p.Ensure, r)
		require.Len(t, days, 21, r)
		assert.Equal(t, "2026-10-01", days[0], r)
		assert.Equal(t, "2026-10-21", days[20], "до вчера")
		assert.Equal(t, at("2026-10-22 01:00"), specFor(p.Ensure, r, "2026-10-21").RunAt, "вчера — с 01:00")
		assert.Equal(t, at("2026-10-22 00:05"), specFor(p.Ensure, r, "2026-10-05").RunAt, "догон — сразу")
	}

	tail := refresh(p, KindAdsTail)
	require.NotNil(t, tail)
	window := periods(tail.Specs, executor.ResourceAdsExpense)
	require.Len(t, window, AdsTailDays)
	assert.Equal(t, "2026-10-21", window[0])
	assert.Equal(t, "2026-10-08", window[AdsTailDays-1])
	for _, s := range tail.Specs {
		assert.True(t, s.Refresh)
		assert.Equal(t, at("2026-10-22 01:00"), s.RunAt)
	}
	assert.Nil(t, refresh(p, KindTail), "кабинет без seller — только реклама")
}

func TestAdsNotPlannedWithoutResourceOrKey(t *testing.T) {
	p := Build(at("2026-10-22 12:00"), []connections.Cabinet{perfOnly("a"), seller("b", adsResources...)}, sourceConfig(msk))
	for _, r := range adsResources {
		assert.Empty(t, periods(p.Ensure, r), r)
	}
	assert.Nil(t, refresh(p, KindAdsTail))
}

func TestAdsDoNotChangeSellerPlan(t *testing.T) {
	base := append([]string{executor.ResourceOrdersFBO}, allFinance...)
	without := Build(at("2026-10-22 03:00"), []connections.Cabinet{seller("a", base...)}, sourceConfig(msk))
	cab := seller("a", append(base, adsResources...)...)
	cab.HasPerformance = true
	with := Build(at("2026-10-22 03:00"), []connections.Cabinet{cab}, sourceConfig(msk))

	var sellerEnsure int
	for _, s := range with.Ensure {
		if !executor.IsAdsResource(s.ResourceType) {
			sellerEnsure++
		}
	}
	assert.Equal(t, len(without.Ensure), sellerEnsure)
	assert.Len(t, with.Refreshes, len(without.Refreshes)+1)
}

func TestAdsReportsPlannedYesterdayAndTailFrom0130WithCampaigns(t *testing.T) {
	all := append(append([]string{}, adsResources...), executor.ResourceAdsStatistics, executor.ResourceAdsStatisticsTail)
	p := Build(at("2026-10-22 00:05"), []connections.Cabinet{perfOnly("a", all...)}, sourceConfig(msk))

	assert.Equal(t, []string{"2026-10-21"}, periods(p.Ensure, executor.ResourceAdsStatistics), "только вчера, без догона")
	assert.Equal(t, []string{"2026-10-22"}, periods(p.Ensure, executor.ResourceAdsStatisticsTail), "хвост — день прохода")
	for _, r := range []string{executor.ResourceAdsStatistics, executor.ResourceAdsStatisticsTail} {
		for _, s := range p.Ensure {
			if s.ResourceType == r {
				assert.Equal(t, at("2026-10-22 01:30"), s.RunAt, r)
				assert.False(t, s.Refresh, r)
			}
		}
	}

	// Без кампаний (ресурс этапа 6 не выдан) — отчёты не ставятся: сейчас
	// ozon.ads.statistics выдаётся всем кабинетам с performance.
	legacy := Build(at("2026-10-22 00:05"), []connections.Cabinet{perfOnly("b", executor.ResourceAdsStatistics)}, sourceConfig(msk))
	assert.Empty(t, periods(legacy.Ensure, executor.ResourceAdsStatistics))
}
