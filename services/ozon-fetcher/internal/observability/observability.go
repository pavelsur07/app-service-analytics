// Package observability — логи сервиса (slog JSON, services/AGENTS.md §6) и
// отправка ошибок в GlitchTip (Sentry-совместимый протокол).
//
// В GlitchTip уходят только записи уровня ERROR — это инциденты по правилу
// уровней CLAUDE.md «Логирование»; WARNING (повторы, 429, таймауты) — только в лог.
// Событие несёт поля записи лога: ключей, тела ответов и сумм в логах нет (§6).
package observability

import (
	"context"
	"fmt"
	"io"
	"log/slog"
	"time"

	"github.com/getsentry/sentry-go"
)

// Service — имя сервиса в логах и тег в GlitchTip.
const Service = "ozon-fetcher"

// FlushTimeout — сколько ждать отправки событий при остановке.
const FlushTimeout = 2 * time.Second

// tagKeys — поля записи, которые становятся тегами (фильтр в GlitchTip);
// остальные — в контекст «log».
var tagKeys = map[string]bool{
	"stage": true, "outcome": true, "error_class": true,
	"company_id": true, "marketplace_account_id": true, "resource_type": true, "endpoint": true, "kind": true,
}

// Capturer — отправка события (sentry.CaptureEvent или фейк в тестах).
type Capturer func(*sentry.Event)

// Setup — логгер сервиса. Без dsn ошибки только пишутся в лог. flush
// вызывать при остановке, до выхода процесса. Ошибка инициализации GlitchTip
// не останавливает сервис (services/AGENTS.md §6): возвращается рабочий
// логгер без отправки и ошибка — для записи в лог.
func Setup(out io.Writer, dsn, release, environment string) (*slog.Logger, func(), error) {
	base := slog.NewJSONHandler(out, nil)
	plain := slog.New(base).With(slog.String("service", Service))
	if dsn == "" {
		return plain, func() {}, nil
	}
	if err := sentry.Init(sentry.ClientOptions{
		Dsn:         dsn,
		Release:     release,
		Environment: environment,
		ServerName:  Service,
	}); err != nil {
		return plain, func() {}, fmt.Errorf("observability: sentry init: %w", err)
	}
	capture := func(e *sentry.Event) { sentry.CaptureEvent(e) }
	logger := slog.New(NewHandler(base, capture)).With(slog.String("service", Service))
	return logger, func() { sentry.Flush(FlushTimeout) }, nil
}

// Handler пишет всё в next и отдаёт записи ERROR в capture.
type Handler struct {
	next    slog.Handler
	capture Capturer
	attrs   []slog.Attr
	group   string
}

func NewHandler(next slog.Handler, capture Capturer) *Handler {
	return &Handler{next: next, capture: capture}
}

func (h *Handler) Enabled(ctx context.Context, l slog.Level) bool { return h.next.Enabled(ctx, l) }

func (h *Handler) WithAttrs(attrs []slog.Attr) slog.Handler {
	clone := *h
	clone.next = h.next.WithAttrs(attrs)
	clone.attrs = append(append([]slog.Attr(nil), h.attrs...), h.prefixed(attrs)...)
	return &clone
}

func (h *Handler) WithGroup(name string) slog.Handler {
	clone := *h
	clone.next = h.next.WithGroup(name)
	if h.group != "" {
		name = h.group + "." + name
	}
	clone.group = name
	return &clone
}

func (h *Handler) prefixed(attrs []slog.Attr) []slog.Attr {
	if h.group == "" {
		return attrs
	}
	out := make([]slog.Attr, len(attrs))
	for i, a := range attrs {
		out[i] = slog.Attr{Key: h.group + "." + a.Key, Value: a.Value}
	}
	return out
}

func (h *Handler) Handle(ctx context.Context, r slog.Record) error {
	err := h.next.Handle(ctx, r)
	if r.Level >= slog.LevelError {
		h.capture(h.event(r))
	}
	return err
}

// event — событие GlitchTip; группировка по сообщению, stage и error_class:
// ошибки одного вида складываются в один issue, а не по issue на кабинет.
func (h *Handler) event(r slog.Record) *sentry.Event {
	e := sentry.NewEvent()
	e.Level = sentry.LevelError
	e.Message = r.Message
	e.Timestamp = r.Time
	e.Logger = Service
	e.Tags = map[string]string{"service": Service}
	extra := sentry.Context{}

	add := func(a slog.Attr) {
		a.Value = a.Value.Resolve()
		if a.Value.Kind() == slog.KindGroup {
			for _, g := range a.Value.Group() {
				extra[a.Key+"."+g.Key] = g.Value.String()
			}
			return
		}
		if tagKeys[a.Key] {
			e.Tags[a.Key] = a.Value.String()
			return
		}
		extra[a.Key] = a.Value.Any()
	}
	for _, a := range h.attrs {
		add(a)
	}
	r.Attrs(func(a slog.Attr) bool {
		add(slog.Attr{Key: h.groupKey(a.Key), Value: a.Value})
		return true
	})

	// Поля записи, кроме тегов, — в контекст «log» события.
	e.Contexts = map[string]sentry.Context{"log": extra}
	e.Fingerprint = []string{Service, r.Message, e.Tags["stage"], e.Tags["error_class"]}
	return e
}

func (h *Handler) groupKey(key string) string {
	if h.group == "" {
		return key
	}
	return h.group + "." + key
}
