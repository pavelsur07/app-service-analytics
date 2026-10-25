// Package planner ставит задачи загрузки под режимы плана
// (docs/tasks/ozon-fetcher-ingestion/stages/stage-11.md): только upsert в
// fetch_task, без собственной загрузки.
package planner

import (
	"slices"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/executor"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store"
)

// Приоритеты задач.
const (
	PriorityCurrentMonth = 10
	PriorityRoutine      = 5
	PriorityBackfill     = 1
)

// Виды проходов с перезагрузкой — не чаще одного раза за свою дату.
const (
	KindTail            = "tail"
	KindTypes           = "accrual_types"
	KindReconcile       = "reconcile_m1"
	KindFinal           = "final_m2"
	KindOrders          = "orders_tail"
	KindAccrualPostings = "accrual_postings_tail"
	KindAdsTail         = "ads_tail"
)

// FirstWave — ресурсы, которые планировщик ставит в Conwix (ADR-043,
// «Первая волна»): пересечение с загрузкой PHP. Остальные ресурсы источника
// (FBS, начисления по отправлениям, типы начислений, реализация,
// взаиморасчёты, асинхронный отчёт рекламы, остатки v4) не ставятся, даже
// если кабинет их поддерживает: код остаётся, включение — отдельное решение
// и правка этого списка, не настройка.
var FirstWave = []string{
	executor.ResourceAccrualByDay,
	executor.ResourceOrdersFBO,
	executor.ResourceProductsList,
	executor.ResourceProductsInfo,
	executor.ResourceAdsCampaigns,
	executor.ResourceAdsExpense,
	executor.ResourceAdsDaily,
}

// AdsTailDays — дней перед сегодня, в которых раз в сутки перечитываются
// расход и статистика рекламы: Ozon дописывает атрибуцию задним числом.
const AdsTailDays = 14

// adsStart — смещение начала загрузки рекламы дня после полуночи МСК: день
// закрыт, отчёты Symfony (04:30) ещё не начались.
const adsStart = time.Hour

// adsReportsStart — отчёты по товарам: после расхода и статистики дня
// (активные кампании — по ним), внутри окна заказа 00:30–04:00.
const adsReportsStart = 90 * time.Minute

// DefaultAccrualPostingsDays — сколько дней перед сегодня перечитываются
// начисления по отправлениям: они копятся неделями (доставка, возвраты).
const DefaultAccrualPostingsDays = 45

// DefaultAccrualPostingsDailyDays — из них перечитываются каждые сутки;
// старшие — раз в неделю: квота метода общая для всех клиентов Ozon.
const DefaultAccrualPostingsDailyDays = 14

// DefaultOrdersTailDays — сколько дней перед сегодня перечитываются раз в
// сутки: так видны смены статусов (доставка, отмена) — фильтр по дате смены
// статуса Ozon игнорирует.
const DefaultOrdersTailDays = 30

// ordersResources — списки отправлений (executor.postingResources).
var ordersResources = []string{executor.ResourceOrdersFBO, executor.ResourceOrdersFBS}

// Config — параметры окон загрузки.
type Config struct {
	Location *time.Location // Europe/Moscow
	// Resources — ресурсы, которые ставятся (DefaultConfig — FirstWave):
	// пересекаются с ресурсами кабинета из выдачи подключений.
	Resources        []string
	TailDays         int // вчера + TailDays предыдущих
	ReconcileWeekday time.Weekday
	FinalDay         int // число месяца финальной перезагрузки M-2
	// OrdersFrom, AdsFrom — первый день отправлений и рекламы (полночь МСК);
	// нулевое значение и дата раньше M-2 — с M-2, как первичная загрузка
	// начислений (ADR-043).
	OrdersFrom     time.Time
	AdsFrom        time.Time
	OrdersTailDays int // дней перед сегодня, перечитываемых раз в сутки (смены статусов)
	// AccrualPostingsDays — дней перед сегодня, в которых перечитываются
	// начисления по отправлениям: первые AccrualPostingsDailyDays — каждые
	// сутки, старшие — в дни, когда возраст дня кратен неделе от границы.
	AccrualPostingsDays      int
	AccrualPostingsDailyDays int
}

// DefaultConfig — значения плана.
func DefaultConfig(loc *time.Location) Config {
	return Config{Location: loc, Resources: FirstWave, TailDays: 3, ReconcileWeekday: time.Monday, FinalDay: 10, OrdersTailDays: DefaultOrdersTailDays,
		AccrualPostingsDays: DefaultAccrualPostingsDays, AccrualPostingsDailyDays: DefaultAccrualPostingsDailyDays}
}

// supports — ресурс ставится: он в наборе конфигурации и доступен кабинету.
func (c Config) supports(cab connections.Cabinet, resource string) bool {
	return slices.Contains(c.Resources, resource) && cab.Supports(resource)
}

// from — первый день загрузки: from, но не раньше m2Start.
func from(day time.Time, m2Start time.Time, loc *time.Location) time.Time {
	if day.IsZero() {
		return m2Start
	}
	day = day.In(loc)
	day = time.Date(day.Year(), day.Month(), day.Day(), 0, 0, 0, 0, loc)
	if day.Before(m2Start) {
		return m2Start
	}
	return day
}

// Refresh — проход с перезагрузкой кабинета, выполняемый один раз за RunDate.
type Refresh struct {
	Kind    string
	RunDate string // YYYY-MM-DD (МСК)
	Account account.Ref
	Specs   []store.TaskSpec
}

// Plan — результат прохода: Ensure ставится всегда (идемпотентно), каждый
// Refresh — один раз за свою дату.
type Plan struct {
	Ensure    []store.TaskSpec
	Refreshes []Refresh
}

// Build — задачи для активных кабинетов на момент now.
func Build(now time.Time, cabinets []connections.Cabinet, cfg Config) Plan {
	local := now.In(cfg.Location)
	today := time.Date(local.Year(), local.Month(), local.Day(), 0, 0, 0, 0, cfg.Location)
	yesterday := today.AddDate(0, 0, -1)
	monthStart := time.Date(today.Year(), today.Month(), 1, 0, 0, 0, 0, cfg.Location)
	m1Start, m2Start := monthStart.AddDate(0, -1, 0), monthStart.AddDate(0, -2, 0)
	runDate := today.Format(time.DateOnly)

	var plan Plan
	for _, cab := range cabinets {
		// Реклама — свой ключ: кабинет без seller её тоже получает.
		planAds(&plan, cab, today, runDate, now, cfg)
		if cab.Seller == nil {
			continue
		}
		tail := Refresh{Kind: KindTail, RunDate: runDate, Account: cab.Account}
		types := Refresh{Kind: KindTypes, RunDate: runDate, Account: cab.Account}
		reconcile := Refresh{Kind: KindReconcile, RunDate: runDate, Account: cab.Account}
		final := Refresh{Kind: KindFinal, RunDate: runDate, Account: cab.Account}

		if cfg.supports(cab, executor.ResourceAccrualByDay) {
			// Первичная загрузка и подхват пропусков: M — главнее, M-1/M-2 — фоном.
			for d := m2Start; !d.After(yesterday); d = d.AddDate(0, 0, 1) {
				priority := PriorityBackfill
				if !d.Before(monthStart) {
					priority = PriorityCurrentMonth
				}
				plan.Ensure = append(plan.Ensure, spec(cab.Account, executor.ResourceAccrualByDay, d.Format(time.DateOnly), priority, false, now))
			}
			for i := 0; i <= cfg.TailDays; i++ {
				d := yesterday.AddDate(0, 0, -i)
				tail.Specs = append(tail.Specs, spec(cab.Account, executor.ResourceAccrualByDay, d.Format(time.DateOnly), PriorityRoutine, true, now))
			}
			if today.Weekday() == cfg.ReconcileWeekday {
				reconcile.Specs = append(reconcile.Specs, days(cab.Account, m1Start, monthStart.AddDate(0, 0, -1), now)...)
			}
			if today.Day() == cfg.FinalDay {
				final.Specs = append(final.Specs, days(cab.Account, m2Start, m1Start.AddDate(0, 0, -1), now)...)
			}
		}
		for _, resource := range []string{executor.ResourceRealization, executor.ResourceMutualSettlement} {
			if !cfg.supports(cab, resource) {
				continue
			}
			for _, month := range []time.Time{m1Start, m2Start} {
				plan.Ensure = append(plan.Ensure, spec(cab.Account, resource, month.Format("2006-01"), PriorityRoutine, false, now))
			}
		}
		if cfg.supports(cab, executor.ResourceAccrualTypes) {
			// ensure — новый кабинет получает справочник сразу; refresh — раз в сутки.
			plan.Ensure = append(plan.Ensure, spec(cab.Account, executor.ResourceAccrualTypes, executor.PeriodRef, PriorityRoutine, false, now))
			types.Specs = append(types.Specs, spec(cab.Account, executor.ResourceAccrualTypes, executor.PeriodRef, PriorityRoutine, true, now))
		}
		orders := Refresh{Kind: KindOrders, RunDate: runDate, Account: cab.Account}
		// Отправления: догон с OrdersFrom (не глубже M-2), сегодня — каждый
		// проход, хвост — раз в сутки.
		ordersFrom := from(cfg.OrdersFrom, m2Start, cfg.Location)
		for _, resource := range ordersResources {
			if !cfg.supports(cab, resource) {
				continue
			}
			for d := ordersFrom; d.Before(today); d = d.AddDate(0, 0, 1) {
				plan.Ensure = append(plan.Ensure, spec(cab.Account, resource, d.Format(time.DateOnly), PriorityBackfill, false, now))
			}
			if !today.Before(ordersFrom) {
				// Обычный приоритет: сегодняшние заказы не опережают финансовые
				// отчёты в общей очереди кабинета.
				plan.Ensure = append(plan.Ensure, spec(cab.Account, resource, today.Format(time.DateOnly), PriorityRoutine, true, now))
			}
			// Вчера перечитывается всегда: последний проход дня не видит
			// отправлений до полуночи.
			for i := 1; i <= max(cfg.OrdersTailDays, 1); i++ {
				d := today.AddDate(0, 0, -i)
				if d.Before(ordersFrom) {
					break
				}
				orders.Specs = append(orders.Specs, spec(cab.Account, resource, d.Format(time.DateOnly), PriorityRoutine, true, now))
			}
		}
		accrualPostings := Refresh{Kind: KindAccrualPostings, RunDate: runDate, Account: cab.Account}
		if cfg.supports(cab, executor.ResourceAccrualPostings) {
			// Дни отправлений до вчера: у сегодняшних начислений почти нет.
			for d := ordersFrom; d.Before(today); d = d.AddDate(0, 0, 1) {
				plan.Ensure = append(plan.Ensure, spec(cab.Account, executor.ResourceAccrualPostings, d.Format(time.DateOnly), PriorityBackfill, false, now))
			}
			for i := 1; i <= cfg.AccrualPostingsDays; i++ {
				d := today.AddDate(0, 0, -i)
				if d.Before(ordersFrom) {
					break
				}
				if i > cfg.AccrualPostingsDailyDays && (i-cfg.AccrualPostingsDailyDays)%7 != 0 {
					continue
				}
				// Низкий приоритет: при сплошных 429 общей квоты метода задача
				// уходит в конец очереди по next_run_at и не занимает слот
				// кабинета раньше догона других ресурсов.
				accrualPostings.Specs = append(accrualPostings.Specs, spec(cab.Account, executor.ResourceAccrualPostings, d.Format(time.DateOnly), PriorityBackfill, true, now))
			}
		}
		// Каталог — снимок дня: только сегодня, без перечитывания (прошлое не
		// загрузить). Подробности — только вместе со списком: id берутся из него.
		if cfg.supports(cab, executor.ResourceProductsList) {
			plan.Ensure = append(plan.Ensure, spec(cab.Account, executor.ResourceProductsList, today.Format(time.DateOnly), PriorityRoutine, false, now))
			if cfg.supports(cab, executor.ResourceProductsInfo) {
				// Минутой позже списка: иначе подробности могут быть взяты первыми
				// и уйти в not_ready на 10 минут.
				plan.Ensure = append(plan.Ensure, spec(cab.Account, executor.ResourceProductsInfo, today.Format(time.DateOnly), PriorityRoutine, false, now.Add(time.Minute)))
			}
		}
		// Остатки — снимок дня: только сегодня, без перечитывания; исполняется
		// с 05:00. Конец недели — снимок понедельника.
		if cfg.supports(cab, executor.ResourceStocks) {
			plan.Ensure = append(plan.Ensure, spec(cab.Account, executor.ResourceStocks, today.Format(time.DateOnly), PriorityRoutine, false,
				today.Add(executor.StocksWindowStart[executor.ResourceStocks])))
		}
		for _, r := range []Refresh{tail, types, reconcile, final, orders, accrualPostings} {
			if len(r.Specs) > 0 {
				plan.Refreshes = append(plan.Refreshes, r)
			}
		}
	}
	return plan
}

// days — перезагрузка всех дней [from; to] с низким приоритетом.
func days(ref account.Ref, from, to, now time.Time) []store.TaskSpec {
	var out []store.TaskSpec
	for d := from; !d.After(to); d = d.AddDate(0, 0, 1) {
		out = append(out, spec(ref, executor.ResourceAccrualByDay, d.Format(time.DateOnly), PriorityBackfill, true, now))
	}
	return out
}

func spec(ref account.Ref, resource, period string, priority int, refresh bool, now time.Time) store.TaskSpec {
	return store.TaskSpec{
		TaskKey:  store.TaskKey{Account: ref, ResourceType: resource, PeriodKey: period},
		Priority: priority,
		Refresh:  refresh,
		RunAt:    now,
	}
}

// planAds — кампании (снимок дня), расход и статистика по дням с
// cfg.AdsFrom (не раньше M-2) и перечитывание AdsTailDays раз в сутки. Слот рекламы у
// кабинета свой — приоритеты соперничают только внутри рекламы.
func planAds(plan *Plan, cab connections.Cabinet, today time.Time, runDate string, now time.Time, cfg Config) {
	if !cab.HasPerformance {
		return
	}
	start := today.Add(adsStart)
	if cfg.supports(cab, executor.ResourceAdsCampaigns) {
		plan.Ensure = append(plan.Ensure, spec(cab.Account, executor.ResourceAdsCampaigns, today.Format(time.DateOnly), PriorityRoutine, false, start))
	}
	monthStart := time.Date(today.Year(), today.Month(), 1, 0, 0, 0, 0, cfg.Location)
	adsFrom := from(cfg.AdsFrom, monthStart.AddDate(0, -2, 0), cfg.Location)
	tail := Refresh{Kind: KindAdsTail, RunDate: runDate, Account: cab.Account}
	for _, resource := range []string{executor.ResourceAdsExpense, executor.ResourceAdsDaily} {
		if !cfg.supports(cab, resource) {
			continue
		}
		for d := adsFrom; d.Before(today); d = d.AddDate(0, 0, 1) {
			runAt := now
			if d.Equal(today.AddDate(0, 0, -1)) {
				// Вчера — не сразу после полуночи: с началом рекламы дня.
				runAt = start
			}
			plan.Ensure = append(plan.Ensure, spec(cab.Account, resource, d.Format(time.DateOnly), PriorityBackfill, false, runAt))
		}
		for i := 1; i <= AdsTailDays; i++ {
			d := today.AddDate(0, 0, -i)
			if d.Before(adsFrom) {
				break
			}
			tail.Specs = append(tail.Specs, spec(cab.Account, resource, d.Format(time.DateOnly), PriorityRoutine, true, start))
		}
	}
	if len(tail.Specs) > 0 {
		plan.Refreshes = append(plan.Refreshes, tail)
	}
	// Отчёты по товарам — только вместе с кампаниями: порции — из их снимка.
	if cfg.supports(cab, executor.ResourceAdsCampaigns) {
		reports := today.Add(adsReportsStart)
		if cfg.supports(cab, executor.ResourceAdsStatistics) {
			plan.Ensure = append(plan.Ensure, spec(cab.Account, executor.ResourceAdsStatistics, today.AddDate(0, 0, -1).Format(time.DateOnly), PriorityRoutine, false, reports))
		}
		if cfg.supports(cab, executor.ResourceAdsStatisticsTail) {
			plan.Ensure = append(plan.Ensure, spec(cab.Account, executor.ResourceAdsStatisticsTail, today.Format(time.DateOnly), PriorityRoutine, false, reports))
		}
	}
}
