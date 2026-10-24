// Package ozon — HTTP-клиент Ozon Seller API: запрос, классификация ответов,
// лог без тела и ключей (services/ozon-fetcher/docs/ozon-api.md).
package ozon

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
)

const (
	// DefaultBaseURL — Ozon Seller API.
	DefaultBaseURL = "https://api-seller.ozon.ru"
	// DefaultTimeout — запрос целиком, как REQUEST_TIMEOUT Symfony-клиента.
	DefaultTimeout = 120 * time.Second
	// MaxBodyBytes — потолок тела ответа. Больше — ошибка, а не обрезанные данные.
	MaxBodyBytes = 64 << 20
)

// ErrBodyTooLarge — тело ответа превысило MaxBodyBytes.
var ErrBodyTooLarge = errors.New("ozon: response body exceeds limit")

// Response — успешный ответ: исходные байты тела без перекодирования.
type Response struct {
	Status   int
	Header   http.Header
	Body     []byte
	Duration time.Duration
}

// Limiter — ограничение скорости на пару (кабинет, endpoint). Wait — до
// запроса, Observe — после: класс ответа (nil — успех) подстраивает лимит.
type Limiter interface {
	Wait(ctx context.Context, ref account.Ref, endpoint string) error
	Observe(ref account.Ref, endpoint string, apiErr *APIError)
}

type noLimit struct{}

func (noLimit) Wait(context.Context, account.Ref, string) error { return nil }
func (noLimit) Observe(account.Ref, string, *APIError)          {}

// Client — транспорт Seller API. Без состояния кабинета: ключи передаются в вызов.
type Client struct {
	baseURL string
	http    *http.Client
	logger  *slog.Logger
	now     func() time.Time
	limiter Limiter
}

// NewClient — httpClient с таймаутом настраивает вызывающий (см. DefaultTimeout).
// Без WithLimiter запросы не ограничиваются.
//
// Запрос с ключом не перенаправляется: http.Client переносит нестандартные
// заголовки Api-Key и Client-Id и на другой хост, а Seller API не
// перенаправляет — ответ 3xx возвращается как есть и классифицируется как
// ошибка. Скачивание файла отчёта по ссылке идёт без ключа, и его редирект
// (CDN хранилища) выполняется, как в источнике.
func NewClient(baseURL string, httpClient *http.Client, logger *slog.Logger, now func() time.Time) *Client {
	hc := *httpClient
	hc.CheckRedirect = checkRedirect
	return &Client{baseURL: baseURL, http: &hc, logger: logger, now: now, limiter: noLimit{}}
}

// checkRedirect — без редиректа для запросов с ключом; для остальных —
// стандартный предел http.Client в 10 переходов.
func checkRedirect(_ *http.Request, via []*http.Request) error {
	if via[0].Header.Get("Api-Key") != "" || via[0].Header.Get("Client-Id") != "" {
		return http.ErrUseLastResponse
	}
	if len(via) >= 10 {
		return errors.New("ozon: stopped after 10 redirects")
	}
	return nil
}

// WithLimiter возвращает копию клиента с ограничением скорости.
func (c *Client) WithLimiter(l Limiter) *Client {
	clone := *c
	clone.limiter = l
	return &clone
}

// Post отправляет JSON на endpoint. Неуспех — *APIError; отмена ctx — ctx.Err().
func (c *Client) Post(ctx context.Context, ref account.Ref, creds Credentials, endpoint string, body any) (Response, error) {
	if err := c.limiter.Wait(ctx, ref, endpoint); err != nil {
		return Response{}, err
	}
	resp, err := c.post(ctx, ref, creds, endpoint, body)
	if err == nil {
		c.limiter.Observe(ref, endpoint, nil)
		return resp, nil
	}
	var apiErr *APIError
	if errors.As(err, &apiErr) {
		c.limiter.Observe(ref, endpoint, apiErr)
	}
	return resp, err
}

func (c *Client) post(ctx context.Context, ref account.Ref, creds Credentials, endpoint string, body any) (Response, error) {
	payload, err := json.Marshal(body)
	if err != nil {
		return Response{}, fmt.Errorf("ozon: encode request %s: %w", endpoint, err)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+endpoint, bytes.NewReader(payload))
	if err != nil {
		return Response{}, fmt.Errorf("ozon: build request %s: %w", endpoint, err)
	}
	req.Header.Set("Client-Id", creds.ClientID)
	req.Header.Set("Api-Key", creds.APIKey)
	req.Header.Set("Content-Type", "application/json")

	started := c.now()
	resp, err := c.http.Do(req)
	if err != nil {
		duration := c.now().Sub(started)
		if ctxErr := ctx.Err(); ctxErr != nil {
			c.log(ctx, ref, endpoint, 0, duration, "canceled")
			return Response{}, ctxErr
		}
		c.log(ctx, ref, endpoint, 0, duration, string(ClassTransient))
		return Response{}, &APIError{Class: ClassTransient, Endpoint: endpoint, Err: err}
	}
	defer func() { _ = resp.Body.Close() }()

	raw, err := io.ReadAll(io.LimitReader(resp.Body, MaxBodyBytes+1))
	duration := c.now().Sub(started)
	if err != nil {
		if ctxErr := ctx.Err(); ctxErr != nil {
			c.log(ctx, ref, endpoint, resp.StatusCode, duration, "canceled")
			return Response{}, ctxErr
		}
		c.log(ctx, ref, endpoint, resp.StatusCode, duration, string(ClassTransient))
		return Response{}, &APIError{Class: ClassTransient, Endpoint: endpoint, Status: resp.StatusCode, Err: err}
	}
	if len(raw) > MaxBodyBytes {
		c.log(ctx, ref, endpoint, resp.StatusCode, duration, string(ClassInvalidResponse))
		return Response{}, &APIError{Class: ClassInvalidResponse, Endpoint: endpoint, Status: resp.StatusCode, Err: ErrBodyTooLarge}
	}

	if apiErr := Classify(endpoint, resp.StatusCode, resp.Header, raw, c.now(), creds.APIKey); apiErr != nil {
		c.log(ctx, ref, endpoint, resp.StatusCode, duration, string(apiErr.Class))
		return Response{}, apiErr
	}

	c.log(ctx, ref, endpoint, resp.StatusCode, duration, "ok")
	return Response{Status: resp.StatusCode, Header: resp.Header, Body: raw, Duration: duration}, nil
}

func (c *Client) log(ctx context.Context, ref account.Ref, endpoint string, status int, duration time.Duration, outcome string) {
	level := slog.LevelInfo
	if outcome != "ok" && outcome != "canceled" {
		level = slog.LevelWarn
	}
	c.logger.Log(ctx, level, "ozon request",
		slog.String("stage", "api_fetch"),
		slog.String("company_id", ref.CompanyID), slog.String("marketplace_account_id", ref.AccountID),
		slog.String("endpoint", endpoint),
		slog.Int("status", status),
		slog.Int64("duration_ms", duration.Milliseconds()),
		slog.String("outcome", outcome),
	)
}
