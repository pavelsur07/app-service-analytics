package observability

import (
	"context"
	"io"
	"log/slog"
)

// Defaults are added at Handle time only when absent, so JSON does not contain
// duplicate keys that could be interpreted differently by log consumers.
type handler struct {
	next  slog.Handler
	bound map[string]bool
}

func New(output io.Writer) *slog.Logger {
	return slog.New(&handler{next: slog.NewJSONHandler(output, nil), bound: map[string]bool{}}).With("service", "ozon-ads-controller", "resource_type", "observe")
}
func (h *handler) Enabled(ctx context.Context, level slog.Level) bool {
	return h.next.Enabled(ctx, level)
}
func (h *handler) Handle(ctx context.Context, record slog.Record) error {
	present := map[string]bool{}
	record.Attrs(func(attr slog.Attr) bool { present[attr.Key] = true; return true })
	for _, attr := range []slog.Attr{slog.String("company_id", ""), slog.String("marketplace_account_id", ""), slog.String("job_id", ""), slog.String("trace_id", ""), slog.Int64("duration_ms", 0)} {
		if !present[attr.Key] && !h.bound[attr.Key] {
			record.AddAttrs(attr)
		}
	}
	if !present["outcome"] && !h.bound["outcome"] {
		outcome := "ok"
		if record.Level >= slog.LevelWarn {
			outcome = "error"
		}
		record.AddAttrs(slog.String("outcome", outcome))
	}
	if !present["error_class"] && !h.bound["error_class"] {
		class := ""
		if record.Level >= slog.LevelWarn {
			class = "unclassified"
		}
		record.AddAttrs(slog.String("error_class", class))
	}
	return h.next.Handle(ctx, record)
}
func (h *handler) WithAttrs(attrs []slog.Attr) slog.Handler {
	bound := make(map[string]bool, len(h.bound)+len(attrs))
	for key, value := range h.bound {
		bound[key] = value
	}
	for _, attr := range attrs {
		bound[attr.Key] = true
	}
	return &handler{next: h.next.WithAttrs(attrs), bound: bound}
}
func (h *handler) WithGroup(name string) slog.Handler {
	return &handler{next: h.next.WithGroup(name), bound: h.bound}
}
