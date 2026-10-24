// Package ratelimit — лимит запросов на пару (кабинет, endpoint) с
// адаптацией на 429 (AIMD). Реализует ozon.Limiter (docs/ozon-api.md «Лимиты»).
package ratelimit

import (
	"context"
	"sync"
	"time"

	"golang.org/x/time/rate"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozonperf"
)

// EndpointLimit — границы скорости endpoint'а, запросов в секунду.
type EndpointLimit struct {
	Start        float64
	Floor        float64
	Ceiling      float64
	DefaultPause time.Duration // пауза после 429 без Retry-After
	// SharedQuota — квота метода общая для всех клиентов Ozon: 429 вызван
	// чужой нагрузкой, поэтому темп не снижается и шторм не считается —
	// только пауза.
	SharedQuota bool
}

// Config — лимиты по endpoint'ам и правило роста.
type Config struct {
	Endpoints    map[string]EndpointLimit
	Fallback     EndpointLimit // для endpoint'а вне Endpoints
	GrowAfter    int           // успехов подряд до роста
	IncreaseStep float64       // прирост скорости, запросов/с
}

// DefaultConfig — консервативные дефолты docs/ozon-api.md: Ozon не
// публикует лимиты финансовых методов.
func DefaultConfig() Config {
	return Config{
		Endpoints: map[string]EndpointLimit{
			// Реальный кабинет 17.10.2026: 429 уже при 0,3 запроса/с (docs/ozon-api.md).
			ozon.EndpointAccrualByDay:     {Start: 0.3, Floor: 0.05, Ceiling: 2, DefaultPause: 10 * time.Second},
			ozon.EndpointAccrualTypes:     {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			ozon.EndpointRealization:      {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 900 * time.Second},
			ozon.EndpointMutualSettlement: {Start: 0.1, Floor: 0.02, Ceiling: 0.5, DefaultPause: 900 * time.Second},
			ozon.EndpointReportInfo:       {Start: 0.5, Floor: 0.1, Ceiling: 2, DefaultPause: 30 * time.Second},
			// Списки отправлений: ключ кабинета общий с Symfony, пока тот не
			// отключён (docs/tasks/marketplace-data-ingestion/plan-ozon.md) — старт ниже обычного.
			ozon.EndpointPostingFBOList: {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			ozon.EndpointPostingFBSList: {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			// Замер 22.10.2026: 50 запросов на окно 5 с на всех клиентов метода,
			// окно пустеет за 1–3 с — запрос раз в окно (docs/ozon-api.md).
			ozon.EndpointAccrualPostings: {Start: 0.2, Floor: 0.2, Ceiling: 0.2, DefaultPause: 5 * time.Second, SharedQuota: true},
			// Каталог: 2–3 запроса на кабинет в сутки.
			ozon.EndpointProductList:     {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			ozon.EndpointProductInfoList: {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			// Реклама (Performance API): лимиты не опубликованы, единицы запросов на кабинет в сутки.
			ozonperf.EndpointCampaigns: {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			ozonperf.EndpointExpense:   {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			ozonperf.EndpointDaily:     {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			// Заказ отчёта — 1 в 120 с на кабинет (разнос как у Symfony); 429 на
			// заказ исполнитель переносит на следующее окно.
			ozonperf.EndpointStatistics: {Start: 1.0 / 120, Floor: 1.0 / 120, Ceiling: 1.0 / 120, DefaultPause: 120 * time.Second},
			// Опрос статуса лимитом Ozon не ограничен (опыт Symfony); скачивание — редко.
			ozonperf.EndpointStatisticsStatus: {Start: 0.5, Floor: 0.1, Ceiling: 1, DefaultPause: 30 * time.Second},
			ozonperf.EndpointStatisticsReport: {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			// Остатки: 2 запроса на снимок.
			ozon.EndpointStocks: {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
		},
		Fallback:     EndpointLimit{Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
		GrowAfter:    20,
		IncreaseStep: 0.1,
	}
}

type key struct {
	ref      account.Ref
	endpoint string
}

type pair struct {
	mu          sync.Mutex
	limit       EndpointLimit
	override    float64 // 0 — не задано
	current     float64
	successes   int
	pausedUntil time.Time
	bucket      *rate.Limiter

	floorHits   []time.Time // 429, пришедшие уже на минимальной скорости
	stormSentAt time.Time
}

func (p *pair) ceiling() float64 {
	if p.override > 0 {
		return max(p.override, p.limit.Floor)
	}
	return p.limit.Ceiling
}

func (p *pair) clamp(v float64) float64 { return min(max(v, p.limit.Floor), p.ceiling()) }

// setCurrent меняет скорость; возвращает true, если она изменилась.
func (p *pair) setCurrent(v float64) bool {
	v = p.clamp(v)
	if v == p.current {
		return false
	}
	p.current = v
	p.bucket.SetLimit(rate.Limit(v))
	return true
}

// Storm — шторм 429: скорость пары уже на минимуме, а Ozon продолжает
// отказывать. Замедляться дальше некуда — нужен человек.
type Storm struct {
	Account  account.Ref
	Endpoint string
	Hits     int           // 429 на минимуме за окно
	Window   time.Duration // окно подсчёта
	Floor    float64       // текущая (минимальная) скорость, запросов/с
}

// StormAlert — порог сигнала о шторме.
type StormAlert struct {
	Threshold int // столько 429 на минимуме за Window — сигнал
	Window    time.Duration
	Cooldown  time.Duration // не чаще раза на пару
	Notify    func(Storm)
}

// DefaultStormAlert — 3 отказа на минимуме за час, сигнал не чаще раза в час.
func DefaultStormAlert(notify func(Storm)) StormAlert {
	return StormAlert{Threshold: 3, Window: time.Hour, Cooldown: time.Hour, Notify: notify}
}

// Registry — лимиты всех пар. Безопасен для параллельного использования.
type Registry struct {
	cfg      Config
	now      func() time.Time
	sleep    func(context.Context, time.Duration) error
	onChange func(ref account.Ref, endpoint string, rps float64)
	storm    StormAlert

	mu    sync.Mutex
	pairs map[key]*pair
}

// New — now и sleep — параметры ради детерминированных тестов;
// onChange (может быть nil) получает каждое изменение скорости.
func New(cfg Config, now func() time.Time, sleep func(context.Context, time.Duration) error, onChange func(ref account.Ref, endpoint string, rps float64)) *Registry {
	if onChange == nil {
		onChange = func(account.Ref, string, float64) {}
	}
	return &Registry{cfg: cfg, now: now, sleep: sleep, onChange: onChange, pairs: map[key]*pair{}}
}

func (r *Registry) get(ref account.Ref, endpoint string) *pair {
	r.mu.Lock()
	defer r.mu.Unlock()
	k := key{ref, endpoint}
	if p, ok := r.pairs[k]; ok {
		return p
	}
	limit, ok := r.cfg.Endpoints[endpoint]
	if !ok {
		limit = r.cfg.Fallback
	}
	p := &pair{limit: limit}
	p.current = p.clamp(limit.Start)
	p.bucket = rate.NewLimiter(rate.Limit(p.current), 1)
	r.pairs[k] = p
	return p
}

// WithStormAlert включает сигнал о шторме 429 (вызывать до использования).
func (r *Registry) WithStormAlert(a StormAlert) *Registry {
	r.storm = a
	return r
}

// Seed — состояние пары из хранилища при старте: override (0 — нет) и
// сохранённая адаптированная скорость (0 — нет).
func (r *Registry) Seed(ref account.Ref, endpoint string, override, saved float64) {
	p := r.get(ref, endpoint)
	p.mu.Lock()
	defer p.mu.Unlock()
	p.override = max(override, 0)
	switch {
	case saved > 0:
		p.setCurrent(saved)
	case p.override > 0:
		p.setCurrent(p.override)
	default:
		p.setCurrent(p.current)
	}
}

// SetOverride — ручное значение по кабинету (0 — снять); ограничивает потолок.
func (r *Registry) SetOverride(ref account.Ref, endpoint string, override float64) {
	p := r.get(ref, endpoint)
	p.mu.Lock()
	p.override = max(override, 0)
	changed := p.setCurrent(p.current)
	current := p.current
	p.mu.Unlock()
	if changed {
		r.onChange(ref, endpoint, current)
	}
}

// Rate — текущая скорость пары, запросов/с.
func (r *Registry) Rate(ref account.Ref, endpoint string) float64 {
	p := r.get(ref, endpoint)
	p.mu.Lock()
	defer p.mu.Unlock()
	return p.current
}

// Wait ждёт паузу после 429 и токен пары. Пауза проверяется и после
// получения токена: запрос, стоявший в очереди за токеном, пока сосед получил
// 429, не должен проскочить паузу (SetLimit не переносит уже выданные резервы).
func (r *Registry) Wait(ctx context.Context, ref account.Ref, endpoint string) error {
	p := r.get(ref, endpoint)
	for {
		if err := r.waitPause(ctx, p); err != nil {
			return err
		}
		if err := p.bucket.Wait(ctx); err != nil {
			return err
		}
		if p.pauseLeft(r.now()) <= 0 {
			return nil
		}
	}
}

func (r *Registry) waitPause(ctx context.Context, p *pair) error {
	for {
		wait := p.pauseLeft(r.now())
		if wait <= 0 {
			return nil
		}
		if err := r.sleep(ctx, wait); err != nil {
			return err
		}
	}
}

func (p *pair) pauseLeft(now time.Time) time.Duration {
	p.mu.Lock()
	defer p.mu.Unlock()
	return p.pausedUntil.Sub(now)
}

// Observe подстраивает скорость по исходу запроса (apiErr nil — успех).
func (r *Registry) Observe(ref account.Ref, endpoint string, apiErr *ozon.APIError) {
	p := r.get(ref, endpoint)
	p.mu.Lock()
	changed := false
	var storm *Storm
	switch {
	case apiErr == nil:
		p.successes++
		if r.cfg.GrowAfter > 0 && p.successes >= r.cfg.GrowAfter {
			p.successes = 0
			changed = p.setCurrent(p.current + r.cfg.IncreaseStep)
		}
	case apiErr.Class == ozon.ClassRateLimit:
		p.successes = 0
		if !p.limit.SharedQuota {
			atFloor := p.current <= p.limit.Floor
			changed = p.setCurrent(p.current / 2)
			storm = r.trackStorm(p, ref, endpoint, atFloor)
		}
		pause := apiErr.RetryAfter
		if pause <= 0 {
			pause = p.limit.DefaultPause
		}
		if until := r.now().Add(pause); until.After(p.pausedUntil) {
			p.pausedUntil = until
		}
	default:
		// Серия успехов — подряд: любая ошибка её прерывает, скорость не меняет.
		p.successes = 0
	}
	current := p.current
	p.mu.Unlock()
	if changed {
		r.onChange(ref, endpoint, current)
	}
	if storm != nil {
		r.storm.Notify(*storm)
	}
}

// trackStorm учитывает 429 на минимальной скорости; возвращает сигнал, когда
// их за окно набралось Threshold и с прошлого сигнала прошло Cooldown.
// Вызывается под p.mu.
func (r *Registry) trackStorm(p *pair, ref account.Ref, endpoint string, atFloor bool) *Storm {
	if r.storm.Notify == nil || r.storm.Threshold <= 0 {
		return nil
	}
	if !atFloor {
		p.floorHits = p.floorHits[:0]
		return nil
	}
	now := r.now()
	kept := p.floorHits[:0]
	for _, at := range p.floorHits {
		if now.Sub(at) < r.storm.Window {
			kept = append(kept, at)
		}
	}
	p.floorHits = append(kept, now)
	if len(p.floorHits) < r.storm.Threshold {
		return nil
	}
	if !p.stormSentAt.IsZero() && now.Sub(p.stormSentAt) < r.storm.Cooldown {
		return nil
	}
	p.stormSentAt = now
	return &Storm{Account: ref, Endpoint: endpoint, Hits: len(p.floorHits), Window: r.storm.Window, Floor: p.current}
}

var _ ozon.Limiter = (*Registry)(nil)
