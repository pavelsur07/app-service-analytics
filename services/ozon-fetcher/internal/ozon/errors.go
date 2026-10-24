package ozon

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"time"
)

// Class — класс ответа Ozon; определяет реакцию исполнителя
// (docs/ozon-api.md «Классы ответов»).
type Class string

const (
	ClassRateLimit        Class = "rate_limit"
	ClassTransient        Class = "transient"
	ClassAuth             Class = "auth"
	ClassObsoleteEndpoint Class = "obsolete_endpoint"
	ClassInvalidRequest   Class = "invalid_request"
	ClassInvalidResponse  Class = "invalid_response"
	ClassNotReady         Class = "not_ready"
)

// obsoleteCode — код Ozon для снятого метода (400 {"code":9,...}, 09.09.2026).
const obsoleteCode = 9

const messageLimit = 200

// APIError — неуспешный ответ Ozon или сбой транспорта.
type APIError struct {
	Class      Class
	Endpoint   string
	Status     int // 0 — ответа не было (сеть, таймаут)
	Code       int // code из конверта ошибки Ozon, 0 — нет
	Message    string
	RetryAfter time.Duration
	Err        error // причина для сбоя транспорта
}

func (e *APIError) Error() string {
	if e.Status == 0 {
		return fmt.Sprintf("ozon: %s %s: %v", e.Class, e.Endpoint, e.Err)
	}
	if e.Message != "" {
		// Текст ошибки Ozon (не тело целиком, до messageLimit): без него отказ
		// метода в журнале неотличим от прочих 400 (#439, #442).
		return fmt.Sprintf("ozon: %s %s: HTTP %d code %d: %s", e.Class, e.Endpoint, e.Status, e.Code, e.Message)
	}
	if e.Err != nil {
		// Причина проверки формы ответа (invalid_response, not_ready при 200).
		return fmt.Sprintf("ozon: %s %s: HTTP %d: %v", e.Class, e.Endpoint, e.Status, e.Err)
	}
	return fmt.Sprintf("ozon: %s %s: HTTP %d code %d", e.Class, e.Endpoint, e.Status, e.Code)
}

func (e *APIError) Unwrap() error { return e.Err }

// errorEnvelope — конверт ошибки Ozon: {"code": <int>, "message": "..."}.
type errorEnvelope struct {
	Code    int    `json:"code"`
	Message string `json:"message"`
}

// Classify сопоставляет неуспешный HTTP-ответ классу. Для 2xx возвращает nil:
// форма успешного ответа проверяется на уровне ресурса.
func Classify(endpoint string, status int, header http.Header, body []byte, now time.Time) *APIError {
	if status >= 200 && status < 300 {
		return nil
	}

	var env errorEnvelope
	_ = json.Unmarshal(body, &env)
	apiErr := &APIError{
		Endpoint: endpoint,
		Status:   status,
		Code:     env.Code,
		Message:  truncate(env.Message, messageLimit),
	}

	switch {
	case status == http.StatusTooManyRequests:
		apiErr.Class = ClassRateLimit
		apiErr.RetryAfter = parseRetryAfter(header.Get("Retry-After"), now)
	case status >= 500:
		apiErr.Class = ClassTransient
	case status == http.StatusUnauthorized || status == http.StatusForbidden:
		apiErr.Class = ClassAuth
	case status == http.StatusBadRequest && isObsolete(env, body):
		apiErr.Class = ClassObsoleteEndpoint
	default:
		apiErr.Class = ClassInvalidRequest
	}
	return apiErr
}

func isObsolete(env errorEnvelope, body []byte) bool {
	return env.Code == obsoleteCode || bytes.Contains(bytes.ToLower(body), []byte("obsolete method"))
}

// parseRetryAfter понимает секунды и HTTP-date; битое или прошедшее — 0.
func parseRetryAfter(value string, now time.Time) time.Duration {
	value = strings.TrimSpace(value)
	if value == "" {
		return 0
	}
	if seconds, err := strconv.Atoi(value); err == nil {
		if seconds <= 0 {
			return 0
		}
		return time.Duration(seconds) * time.Second
	}
	if at, err := http.ParseTime(value); err == nil {
		if d := at.Sub(now); d > 0 {
			return d
		}
	}
	return 0
}

func truncate(s string, limit int) string {
	if len([]rune(s)) <= limit {
		return s
	}
	return string([]rune(s)[:limit])
}
