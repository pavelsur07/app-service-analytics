// Package connections — активные подключения и ключи из PHP Conwix
// (контракт services/_contracts/marketplace-connections/v1, ADR-043 п. 4).
// Кэш — только в памяти и заменяется только целиком собранным списком.
package connections

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/url"
	"regexp"
	"slices"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozonperf"
)

const (
	endpoint      = "/api/internal/marketplace-connections"
	schemaVersion = "1"
	maxBodyBytes  = 8 << 20
	// PageLimit — размер страницы выдачи: максимум контракта (CLAUDE.md §5).
	PageLimit = 200
	// maxPages — обход, не закончившийся за столько страниц, считается
	// сбоем: выдача не сходится, а не «очень много подключений».
	maxPages = 1000
	// ForceRefreshMinInterval — 401 у нескольких кабинетов не превращается в шторм запросов.
	ForceRefreshMinInterval = 30 * time.Second
)

// Cabinet — подключение Ozon. Ключи — только в памяти, при печати скрыты.
type Cabinet struct {
	Account        account.Ref
	UpdatedAt      time.Time
	Seller         *ozon.Credentials     // nil — ключа Seller API нет
	Performance    *ozonperf.Credentials // nil — ключа Performance API нет
	HasPerformance bool
	ResourceTypes  []string
}

// Supports — ресурс доступен с имеющимися ключами.
func (c Cabinet) Supports(resourceType string) bool {
	for _, rt := range c.ResourceTypes {
		if rt == resourceType {
			return true
		}
	}
	return false
}

// Client — постраничный GET выдачи у PHP.
type Client struct {
	baseURL string
	token   string
	http    *http.Client
}

func NewClient(baseURL, token string, httpClient *http.Client) *Client {
	return &Client{baseURL: baseURL, token: token, http: httpClient}
}

// ErrInvalidSnapshot — ответ не соответствует контракту v1 (contract_violation,
// services/AGENTS.md §3): обход отвергнут целиком, кэш не меняется. Отдельное
// битое подключение — тоже нарушение контракта, а не пропуск: пропуск
// выглядел бы для сервиса как отзыв подключения.
var ErrInvalidSnapshot = errors.New("connections: contract_violation")

// Рабочий разбор строгий и повторяет schema.json: ключи каждого объекта
// сверяются с допустимыми побайтно (encoding/json сопоставляет имена без
// учёта регистра, DisallowUnknownFields этого не ловит), обязательные
// nullable-поля (next_cursor, seller, performance) различаются как «нет
// поля» и «null», после документа ничего нет.
type wireSnapshot struct {
	SchemaVersion string            `json:"schema_version"`
	GeneratedAt   string            `json:"generated_at"`
	Connections   []json.RawMessage `json:"connections"` // каждое — strictDecode в wireCabinet
	RawCursor     json.RawMessage   `json:"next_cursor"` // обязателен: null — страница последняя
	NextCursor    *string           `json:"-"`
}

type wireCabinet struct {
	AccountID     string          `json:"marketplace_account_id"`
	CompanyID     string          `json:"company_id"`
	Provider      string          `json:"provider"`
	UpdatedAt     string          `json:"updated_at"`
	Credentials   json.RawMessage `json:"credentials"`
	ResourceTypes *[]string       `json:"resource_types"`
}

type wireCredentials struct {
	Seller      json.RawMessage `json:"seller"`
	Performance json.RawMessage `json:"performance"`
}

type wireSeller struct {
	ClientID string `json:"client_id"`
	APIKey   string `json:"api_key"`
}

type wirePerformance struct {
	ClientID     string `json:"client_id"`
	ClientSecret string `json:"client_secret"`
}

// Fetch обходит выдачу по курсору. Сбой или нарушение контракта на любой
// странице — ошибка всего обхода: неполный список кэш не заменяет
// (ADR-043 п. 4).
func (c *Client) Fetch(ctx context.Context) ([]Cabinet, error) {
	var cabinets []Cabinet
	seen := map[string]bool{}
	cursor := ""
	for range maxPages {
		snap, err := c.fetchPage(ctx, cursor)
		if err != nil {
			return nil, err
		}
		for i, raw := range snap.Connections {
			cab, err := toCabinet(raw)
			if err != nil {
				return nil, fmt.Errorf("%w: connection %d: %w", ErrInvalidSnapshot, i, err)
			}
			// Подключение принадлежит одной компании: повтор — даже под
			// другой company_id — значит, что достоверной записи нет.
			if seen[cab.Account.AccountID] {
				return nil, fmt.Errorf("%w: duplicate marketplace_account_id %s", ErrInvalidSnapshot, cab.Account.AccountID)
			}
			seen[cab.Account.AccountID] = true
			cabinets = append(cabinets, cab)
		}
		if snap.NextCursor == nil {
			sort.Slice(cabinets, func(i, j int) bool { return cabinets[i].Account.AccountID < cabinets[j].Account.AccountID })
			if cabinets == nil {
				cabinets = []Cabinet{}
			}
			return cabinets, nil
		}
		if *snap.NextCursor == "" || *snap.NextCursor == cursor {
			return nil, fmt.Errorf("%w: next_cursor does not advance", ErrInvalidSnapshot)
		}
		cursor = *snap.NextCursor
	}
	return nil, fmt.Errorf("%w: more than %d pages", ErrInvalidSnapshot, maxPages)
}

func (c *Client) fetchPage(ctx context.Context, cursor string) (wireSnapshot, error) {
	q := url.Values{"provider": {"ozon"}, "limit": {strconv.Itoa(PageLimit)}}
	if cursor != "" {
		q.Set("cursor", cursor)
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.baseURL+endpoint+"?"+q.Encode(), nil)
	if err != nil {
		return wireSnapshot{}, fmt.Errorf("connections: build request: %w", err)
	}
	req.Header.Set("Authorization", "Bearer "+c.token)
	req.Header.Set("Accept", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		return wireSnapshot{}, fmt.Errorf("connections: request: %w", err)
	}
	defer func() { _ = resp.Body.Close() }()

	if resp.StatusCode != http.StatusOK {
		return wireSnapshot{}, fmt.Errorf("connections: HTTP %d", resp.StatusCode)
	}
	raw, err := io.ReadAll(io.LimitReader(resp.Body, maxBodyBytes+1))
	if err != nil {
		return wireSnapshot{}, fmt.Errorf("connections: read body: %w", err)
	}
	if len(raw) > maxBodyBytes {
		return wireSnapshot{}, fmt.Errorf("%w: body exceeds limit", ErrInvalidSnapshot)
	}
	return parsePage(raw)
}

// Допустимые ключи объектов schema.json.
var (
	snapshotKeys    = []string{"schema_version", "generated_at", "connections", "next_cursor"}
	cabinetKeys     = []string{"marketplace_account_id", "company_id", "provider", "updated_at", "credentials", "resource_types"}
	credentialsKeys = []string{"seller", "performance"}
	sellerKeys      = []string{"client_id", "api_key"}
	performanceKeys = []string{"client_id", "client_secret"}
)

// strictDecode — один JSON-объект с ключами только из keys (побайтно) и без
// данных после него; затем разбор в v.
func strictDecode(raw []byte, v any, keys []string) error {
	dec := json.NewDecoder(bytes.NewReader(raw))
	var fields map[string]json.RawMessage
	if err := dec.Decode(&fields); err != nil {
		return err
	}
	if _, err := dec.Token(); !errors.Is(err, io.EOF) {
		return errors.New("trailing data")
	}
	if fields == nil {
		return errors.New("not an object")
	}
	for k := range fields {
		if !slices.Contains(keys, k) {
			return fmt.Errorf("unknown field %q", k)
		}
	}
	return json.Unmarshal(raw, v)
}

// parsePage — страница выдачи; нарушение формы страницы — отказ всего обхода.
func parsePage(raw []byte) (wireSnapshot, error) {
	var snap wireSnapshot
	if err := strictDecode(raw, &snap, snapshotKeys); err != nil {
		return wireSnapshot{}, fmt.Errorf("%w: %w", ErrInvalidSnapshot, err)
	}
	if snap.SchemaVersion != schemaVersion {
		return wireSnapshot{}, fmt.Errorf("%w: schema_version %q", ErrInvalidSnapshot, snap.SchemaVersion)
	}
	if _, err := parseDateTime(snap.GeneratedAt); err != nil {
		return wireSnapshot{}, fmt.Errorf("%w: generated_at", ErrInvalidSnapshot)
	}
	if snap.Connections == nil {
		return wireSnapshot{}, fmt.Errorf("%w: no connections array", ErrInvalidSnapshot)
	}
	if len(snap.Connections) > PageLimit {
		return wireSnapshot{}, fmt.Errorf("%w: page exceeds limit", ErrInvalidSnapshot)
	}
	if len(snap.RawCursor) == 0 {
		return wireSnapshot{}, fmt.Errorf("%w: no next_cursor", ErrInvalidSnapshot)
	}
	if err := json.Unmarshal(snap.RawCursor, &snap.NextCursor); err != nil {
		return wireSnapshot{}, fmt.Errorf("%w: next_cursor", ErrInvalidSnapshot)
	}
	return snap, nil
}

// dateTimeForm — форма date-time RFC 3339. time.Parse с ней не совпадает
// в обе стороны: мягче (запятая в дробной части, однозначный час, смещение
// +24:00) и строже (строчные t и z, високосная секунда 60).
var dateTimeForm = regexp.MustCompile(`^(\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:)(\d{2})(\.\d+)?([Zz]|[+-]([01]\d|2[0-3]):[0-5]\d)$`)

// parseDateTime — date-time по схеме: сначала форма, затем значения полей.
// Строчные разделители приводятся к T и Z, секунда 60 — к 59: момент
// изменения подключения точнее секунды не нужен.
func parseDateTime(s string) (time.Time, error) {
	m := dateTimeForm.FindStringSubmatch(s)
	if m == nil {
		return time.Time{}, errors.New("date-time is not RFC 3339")
	}
	if m[2] == "60" {
		m[2] = "59"
	}
	return time.Parse(time.RFC3339, strings.ToUpper(m[1]+m[2]+m[3]+m[4]))
}

// nullable — обязательное поле, которое может быть null: нет поля — ошибка,
// null — false, объект — разобран строго в v.
func nullable(raw json.RawMessage, v any, keys []string) (bool, error) {
	if len(raw) == 0 {
		return false, errors.New("missing")
	}
	if bytes.Equal(raw, []byte("null")) {
		return false, nil
	}
	return true, strictDecode(raw, v, keys)
}

func toCabinet(raw json.RawMessage) (Cabinet, error) {
	var w wireCabinet
	if err := strictDecode(raw, &w, cabinetKeys); err != nil {
		return Cabinet{}, err
	}
	if w.Provider != "ozon" {
		return Cabinet{}, fmt.Errorf("provider %q", w.Provider)
	}
	ref, err := account.Parse(w.CompanyID, w.AccountID)
	if err != nil {
		return Cabinet{}, err
	}
	updatedAt, err := parseDateTime(w.UpdatedAt)
	if err != nil {
		return Cabinet{}, errors.New("updated_at")
	}
	if len(w.Credentials) == 0 {
		return Cabinet{}, errors.New("no credentials")
	}
	var creds wireCredentials
	if err := strictDecode(w.Credentials, &creds, credentialsKeys); err != nil {
		return Cabinet{}, fmt.Errorf("credentials: %w", err)
	}
	if w.ResourceTypes == nil {
		return Cabinet{}, errors.New("no resource_types")
	}
	for _, r := range *w.ResourceTypes {
		if r == "" {
			return Cabinet{}, errors.New("empty resource_type")
		}
	}
	cab := Cabinet{Account: ref, UpdatedAt: updatedAt, ResourceTypes: *w.ResourceTypes}
	var seller wireSeller
	has, err := nullable(creds.Seller, &seller, sellerKeys)
	if err != nil {
		return Cabinet{}, fmt.Errorf("credentials.seller: %w", err)
	}
	if has {
		if seller.ClientID == "" || seller.APIKey == "" {
			return Cabinet{}, errors.New("credentials.seller: empty key")
		}
		cab.Seller = &ozon.Credentials{ClientID: seller.ClientID, APIKey: seller.APIKey}
	}
	var perf wirePerformance
	has, err = nullable(creds.Performance, &perf, performanceKeys)
	if err != nil {
		return Cabinet{}, fmt.Errorf("credentials.performance: %w", err)
	}
	if has {
		if perf.ClientID == "" || perf.ClientSecret == "" {
			return Cabinet{}, errors.New("credentials.performance: empty key")
		}
		cab.Performance = &ozonperf.Credentials{ClientID: perf.ClientID, ClientSecret: perf.ClientSecret}
		cab.HasPerformance = true
	}
	return cab, nil
}

// Fetcher — источник снимка (Client или фейк в тестах).
type Fetcher interface {
	Fetch(ctx context.Context) ([]Cabinet, error)
}

// Cache — последний успешный снимок. Безопасен для параллельного чтения.
type Cache struct {
	source Fetcher
	logger *slog.Logger
	now    func() time.Time

	mu          sync.RWMutex
	byRef       map[account.Ref]Cabinet
	list        []Cabinet
	ready       bool
	lastAttempt time.Time
	refreshMu   sync.Mutex
	allow       map[string]bool // пусто — все кабинеты
}

func NewCache(source Fetcher, logger *slog.Logger, now func() time.Time) *Cache {
	return &Cache{source: source, logger: logger, now: now, byRef: map[account.Ref]Cabinet{}}
}

// WithAllowlist — грузить только подключения, чей marketplace_account_id или
// company_id в списке (поэтапное включение на проде); пустой список — все.
// Семантика пустого списка меняется на «ни одного» на этапе 5 плана
// (ADR-043 п. 5); до него планировщик выключен.
func (c *Cache) WithAllowlist(ids []string) *Cache {
	c.allow = map[string]bool{}
	for _, id := range ids {
		if id = strings.TrimSpace(id); id != "" {
			c.allow[id] = true
		}
	}
	return c
}

func (c *Cache) allowed(cab Cabinet) bool {
	return len(c.allow) == 0 || c.allow[cab.Account.AccountID] || c.allow[cab.Account.CompanyID]
}

// Ready — есть хотя бы один успешный снимок.
func (c *Cache) Ready() bool {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.ready
}

// List — кабинеты последнего снимка; глубокая копия: вызывающий не меняет кэш.
func (c *Cache) List() []Cabinet {
	c.mu.RLock()
	defer c.mu.RUnlock()
	out := make([]Cabinet, len(c.list))
	for i, cab := range c.list {
		out[i] = cab.clone()
	}
	return out
}

func (c *Cache) Get(ref account.Ref) (Cabinet, bool) {
	c.mu.RLock()
	defer c.mu.RUnlock()
	cab, ok := c.byRef[ref]
	return cab.clone(), ok
}

func (c Cabinet) clone() Cabinet {
	if c.Seller != nil {
		seller := *c.Seller
		c.Seller = &seller
	}
	if c.Performance != nil {
		perf := *c.Performance
		c.Performance = &perf
	}
	c.ResourceTypes = append([]string(nil), c.ResourceTypes...)
	return c
}

// Refresh запрашивает снимок; при сбое кэш не меняется.
func (c *Cache) Refresh(ctx context.Context) error {
	c.refreshMu.Lock()
	defer c.refreshMu.Unlock()
	return c.refreshLocked(ctx)
}

func (c *Cache) refreshLocked(ctx context.Context) error {
	started := c.now()
	c.mu.Lock()
	c.lastAttempt = started
	c.mu.Unlock()

	cabinets, err := c.source.Fetch(ctx)
	duration := c.now().Sub(started).Milliseconds()
	if err != nil {
		if ctx.Err() != nil {
			return ctx.Err()
		}
		c.logger.Warn("connections refresh failed, keeping last snapshot",
			slog.String("stage", "connections"), slog.String("outcome", "error"),
			slog.Int64("duration_ms", duration), slog.String("error", err.Error()))
		return err
	}

	kept := cabinets[:0:0]
	for _, cab := range cabinets {
		if c.allowed(cab) {
			kept = append(kept, cab)
		}
	}
	cabinets = kept
	byRef := make(map[account.Ref]Cabinet, len(cabinets))
	for _, cab := range cabinets {
		byRef[cab.Account] = cab
	}
	c.mu.Lock()
	c.byRef, c.list, c.ready = byRef, cabinets, true
	c.mu.Unlock()

	c.logger.Info("connections refreshed",
		slog.String("stage", "connections"), slog.String("outcome", "ok"),
		slog.Int("cabinets", len(cabinets)), slog.Int64("duration_ms", duration))
	return nil
}

// ForceRefresh — после 401 от Ozon: ключ мог смениться. Не чаще
// ForceRefreshMinInterval; возвращает true, если запрос был.
func (c *Cache) ForceRefresh(ctx context.Context) (bool, error) {
	c.refreshMu.Lock()
	defer c.refreshMu.Unlock()
	c.mu.RLock()
	recent := !c.lastAttempt.IsZero() && c.now().Sub(c.lastAttempt) < ForceRefreshMinInterval
	c.mu.RUnlock()
	if recent {
		return false, nil
	}
	return true, c.refreshLocked(ctx)
}

// Run обновляет снимок сразу и затем раз в interval до отмены ctx.
func (c *Cache) Run(ctx context.Context, interval time.Duration) {
	_ = c.Refresh(ctx)
	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			_ = c.Refresh(ctx)
		}
	}
}
