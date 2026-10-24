package ozonperf

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
	"strings"
	"sync"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
)

const (
	// DefaultBaseURL — Ozon Performance API.
	DefaultBaseURL = "https://api-performance.ozon.ru"
	// DefaultTimeout — запрос целиком.
	DefaultTimeout = 60 * time.Second

	// EndpointToken — OAuth client_credentials.
	EndpointToken = "/api/client/token" //nolint:gosec // G101: путь метода, не секрет

	// tokenSafetyMargin — токен обновляется за 5 мин до истечения
	// (TOKEN_TTL_SAFETY_MARGIN Symfony).
	tokenSafetyMargin = 5 * time.Minute
	// defaultTokenTTL — expires_in без значения (как Symfony).
	defaultTokenTTL = 1800 * time.Second
	// minTokenTTL — нижняя граница срока кэша токена.
	minTokenTTL = time.Minute
)

// token — access_token в памяти; при печати скрыт.
type token struct {
	value   string
	expires time.Time
}

func (t token) String() string { return redacted }

// GoString закрывает %#v.
func (t token) GoString() string { return redacted }

// tokenEntry — токен одного client_id подключения; mu сериализует его
// получение: параллельные задачи ждут один запрос токена.
type tokenEntry struct {
	mu  sync.Mutex
	tok token
}

// tokenKey — подключение и client_id: токен не переходит между
// подключениями даже при общем client_id (services/AGENTS.md §4) —
// иначе подключение с неверным секретом читало бы чужим токеном.
type tokenKey struct {
	ref      account.Ref
	clientID string
}

// Client — транспорт Performance API. Ключи передаются в вызов, токены
// кэшируются по подключению и client_id только в памяти.
type Client struct {
	baseURL string
	http    *http.Client
	logger  *slog.Logger
	now     func() time.Time
	limiter ozon.Limiter

	mu     sync.Mutex
	tokens map[tokenKey]*tokenEntry
}

// NewClient — httpClient с таймаутом настраивает вызывающий (DefaultTimeout).
// Редирект — только на хост Performance: Bearer не уходит дальше.
func NewClient(baseURL string, httpClient *http.Client, logger *slog.Logger, now func() time.Time) *Client {
	hc := *httpClient
	base, _ := url.Parse(baseURL)
	hc.CheckRedirect = func(req *http.Request, via []*http.Request) error {
		if base == nil || !strings.EqualFold(req.URL.Host, base.Host) || !strings.EqualFold(req.URL.Scheme, base.Scheme) {
			return errors.New("ozonperf: redirect to another host or scheme")
		}
		if len(via) >= 5 {
			return errors.New("ozonperf: too many redirects")
		}
		return nil
	}
	return &Client{baseURL: baseURL, http: &hc, logger: logger, now: now, limiter: noLimit{}, tokens: map[tokenKey]*tokenEntry{}}
}

// WithLimiter задаёт ограничение скорости (вызывать до использования).
func (c *Client) WithLimiter(l ozon.Limiter) *Client {
	c.limiter = l
	return c
}

type noLimit struct{}

func (noLimit) Wait(context.Context, account.Ref, string) error { return nil }
func (noLimit) Observe(account.Ref, string, *ozon.APIError)     {}

// Get — GET endpoint с query. 401 — новый токен и один повтор. Неуспех —
// *ozon.APIError; отмена ctx — ctx.Err().
func (c *Client) Get(ctx context.Context, ref account.Ref, creds Credentials, endpoint string, query url.Values) (ozon.Response, error) {
	target := c.baseURL + endpoint
	if len(query) > 0 {
		target += "?" + query.Encode()
	}
	return c.do(ctx, ref, creds, endpoint, http.MethodGet, target, nil)
}

// Post — POST JSON на endpoint; 401 — как у Get.
func (c *Client) Post(ctx context.Context, ref account.Ref, creds Credentials, endpoint string, body any) (ozon.Response, error) {
	payload, err := json.Marshal(body)
	if err != nil {
		return ozon.Response{}, fmt.Errorf("ozonperf: encode request %s: %w", endpoint, err)
	}
	return c.do(ctx, ref, creds, endpoint, http.MethodPost, c.baseURL+endpoint, payload)
}

func (c *Client) do(ctx context.Context, ref account.Ref, creds Credentials, endpoint, method, target string, payload []byte) (ozon.Response, error) {
	for attempt := 0; ; attempt++ {
		tok, err := c.token(ctx, ref, creds)
		if err != nil {
			return ozon.Response{}, err
		}
		if err := c.limiter.Wait(ctx, ref, endpoint); err != nil {
			return ozon.Response{}, err
		}
		header := http.Header{"Authorization": {"Bearer " + tok.value}, "Accept": {"application/json"}}
		if payload != nil {
			header.Set("Content-Type", "application/json")
		}
		resp, err := c.send(ctx, ref, endpoint, method, target, header, payload, creds.ClientSecret, tok.value)
		var apiErr *ozon.APIError
		if errors.As(err, &apiErr) {
			c.limiter.Observe(ref, endpoint, apiErr)
			if apiErr.Status == http.StatusUnauthorized && attempt == 0 {
				// Токен мог истечь раньше срока: новый и один повтор.
				c.dropToken(tokenKey{ref, creds.ClientID}, tok)
				continue
			}
			return ozon.Response{}, err
		}
		if err != nil {
			return ozon.Response{}, err
		}
		c.limiter.Observe(ref, endpoint, nil)
		return resp, nil
	}
}

// token — действующий токен client_id подключения; запрос нового — один
// на подключение и client_id.
func (c *Client) token(ctx context.Context, ref account.Ref, creds Credentials) (token, error) {
	key := tokenKey{ref, creds.ClientID}
	c.mu.Lock()
	entry, ok := c.tokens[key]
	if !ok {
		entry = &tokenEntry{}
		c.tokens[key] = entry
	}
	c.mu.Unlock()

	entry.mu.Lock()
	defer entry.mu.Unlock()
	if entry.tok.value != "" && c.now().Before(entry.tok.expires) {
		return entry.tok, nil
	}
	tok, err := c.requestToken(ctx, ref, creds)
	if err != nil {
		return token{}, err
	}
	entry.tok = tok
	return tok, nil
}

// dropToken забывает токен, если его ещё не заменил параллельный запрос.
func (c *Client) dropToken(key tokenKey, used token) {
	c.mu.Lock()
	entry := c.tokens[key]
	c.mu.Unlock()
	if entry == nil {
		return
	}
	entry.mu.Lock()
	if entry.tok.value == used.value {
		entry.tok = token{}
	}
	entry.mu.Unlock()
}

func (c *Client) requestToken(ctx context.Context, ref account.Ref, creds Credentials) (token, error) {
	payload, err := json.Marshal(map[string]string{
		"client_id":     creds.ClientID,
		"client_secret": creds.ClientSecret,
		"grant_type":    "client_credentials",
	})
	if err != nil {
		return token{}, fmt.Errorf("ozonperf: encode token request: %w", err)
	}
	header := http.Header{"Content-Type": {"application/json"}, "Accept": {"application/json"}}
	resp, err := c.send(ctx, ref, EndpointToken, http.MethodPost, c.baseURL+EndpointToken, header, payload, creds.ClientSecret)
	if err != nil {
		var apiErr *ozon.APIError
		if errors.As(err, &apiErr) && apiErr.Status == http.StatusBadRequest {
			// Неверная пара client_id/secret Ozon отдаёт и как 400.
			apiErr.Class = ozon.ClassAuth
		}
		return token{}, err
	}
	var body struct {
		AccessToken string `json:"access_token"`
		ExpiresIn   int    `json:"expires_in"`
	}
	if err := json.Unmarshal(resp.Body, &body); err != nil || body.AccessToken == "" {
		return token{}, &ozon.APIError{Class: ozon.ClassInvalidResponse, Endpoint: EndpointToken, Status: resp.Status, Err: errors.New("no access_token")}
	}
	ttl := defaultTokenTTL
	if body.ExpiresIn > 0 {
		ttl = time.Duration(body.ExpiresIn) * time.Second
	}
	// Запас 5 мин; короткий токен — не меньше минуты, но не дольше 3/4 его срока.
	cache := ttl - tokenSafetyMargin
	if cache < minTokenTTL {
		cache = min(minTokenTTL, ttl*3/4)
	}
	return token{value: body.AccessToken, expires: c.now().Add(cache)}, nil
}

// messageLimit — длина текста ошибки Ozon в журнале (как у Seller API).
const messageLimit = 200

// safeMessage — текст ошибки Ozon без секретов запроса: декодируется целиком
// (JSON-экранирование тоже), секреты вычищаются, затем текст обрезается —
// он уходит в журнал и GlitchTip, а ответ может повторять присланное.
func safeMessage(body []byte, secrets ...string) string {
	var env struct {
		Message string `json:"message"`
	}
	_ = json.Unmarshal(body, &env)
	msg := env.Message
	for _, s := range secrets {
		if s != "" {
			msg = strings.ReplaceAll(msg, s, redacted)
		}
	}
	if r := []rune(msg); len(r) > messageLimit {
		msg = string(r[:messageLimit])
	}
	return msg
}

// send — один HTTP-запрос: чтение тела с потолком, классификация, журнал без
// query, тела и заголовков.
func (c *Client) send(ctx context.Context, ref account.Ref, endpoint, method, target string, header http.Header, payload []byte, secrets ...string) (ozon.Response, error) {
	var body io.Reader
	if payload != nil {
		body = bytes.NewReader(payload)
	}
	req, err := http.NewRequestWithContext(ctx, method, target, body)
	if err != nil {
		return ozon.Response{}, fmt.Errorf("ozonperf: build request %s: %w", endpoint, err)
	}
	req.Header = header

	started := c.now()
	resp, err := c.http.Do(req)
	if err != nil {
		duration := c.now().Sub(started)
		if ctxErr := ctx.Err(); ctxErr != nil {
			c.log(ctx, ref, endpoint, 0, duration, "canceled")
			return ozon.Response{}, ctxErr
		}
		c.log(ctx, ref, endpoint, 0, duration, string(ozon.ClassTransient))
		// Ошибка транспорта несёт URL с query — в журнал только endpoint.
		return ozon.Response{}, &ozon.APIError{Class: ozon.ClassTransient, Endpoint: endpoint, Err: errors.New("request failed")}
	}
	defer func() { _ = resp.Body.Close() }()

	raw, err := io.ReadAll(io.LimitReader(resp.Body, ozon.MaxBodyBytes+1))
	duration := c.now().Sub(started)
	if err != nil {
		if ctxErr := ctx.Err(); ctxErr != nil {
			c.log(ctx, ref, endpoint, resp.StatusCode, duration, "canceled")
			return ozon.Response{}, ctxErr
		}
		c.log(ctx, ref, endpoint, resp.StatusCode, duration, string(ozon.ClassTransient))
		return ozon.Response{}, &ozon.APIError{Class: ozon.ClassTransient, Endpoint: endpoint, Status: resp.StatusCode, Err: err}
	}
	if len(raw) > ozon.MaxBodyBytes {
		c.log(ctx, ref, endpoint, resp.StatusCode, duration, string(ozon.ClassInvalidResponse))
		return ozon.Response{}, &ozon.APIError{Class: ozon.ClassInvalidResponse, Endpoint: endpoint, Status: resp.StatusCode, Err: ozon.ErrBodyTooLarge}
	}
	if apiErr := ozon.Classify(endpoint, resp.StatusCode, resp.Header, raw, c.now()); apiErr != nil {
		apiErr.Message = safeMessage(raw, secrets...)
		c.log(ctx, ref, endpoint, resp.StatusCode, duration, string(apiErr.Class))
		return ozon.Response{}, apiErr
	}
	c.log(ctx, ref, endpoint, resp.StatusCode, duration, "ok")
	return ozon.Response{Status: resp.StatusCode, Header: resp.Header, Body: raw, Duration: duration}, nil
}

func (c *Client) log(ctx context.Context, ref account.Ref, endpoint string, status int, duration time.Duration, outcome string) {
	level := slog.LevelInfo
	if outcome != "ok" && outcome != "canceled" {
		level = slog.LevelWarn
	}
	c.logger.Log(ctx, level, "ozon performance request",
		slog.String("stage", "api_fetch"),
		slog.String("company_id", ref.CompanyID), slog.String("marketplace_account_id", ref.AccountID),
		slog.String("endpoint", endpoint),
		slog.Int("status", status),
		slog.Int64("duration_ms", duration.Milliseconds()),
		slog.String("outcome", outcome),
	)
}
