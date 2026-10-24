// Package ozonperf — клиент Ozon Performance API (реклама): OAuth-токен по
// client_credentials и запросы с Bearer
// (docs/tasks/marketplace-data-ingestion/stages/ozon-6-ads-sync.md).
package ozonperf

import (
	"encoding/json"
	"log/slog"
)

const redacted = "[redacted]"

// Credentials — ключ Performance кабинета. Живёт только в памяти процесса
// (services/AGENTS.md §3); при печати и логировании секрет скрыт.
type Credentials struct {
	ClientID     string
	ClientSecret string
}

func (c Credentials) String() string {
	return "ozonperf.Credentials{ClientID:" + c.ClientID + ", ClientSecret:" + redacted + "}"
}

// GoString закрывает %#v.
func (c Credentials) GoString() string { return c.String() }

// LogValue закрывает slog: секрет не уходит в лог даже при slog.Any("creds", c).
func (c Credentials) LogValue() slog.Value {
	return slog.GroupValue(slog.String("client_id", c.ClientID), slog.String("client_secret", redacted))
}

// MarshalJSON закрывает вложенную сериализацию.
func (c Credentials) MarshalJSON() ([]byte, error) {
	return json.Marshal(map[string]string{"client_id": c.ClientID, "client_secret": redacted})
}
