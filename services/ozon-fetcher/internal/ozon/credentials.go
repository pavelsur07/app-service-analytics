package ozon

import (
	"encoding/json"
	"log/slog"
)

// Credentials — ключи Seller API кабинета. Живут только в памяти процесса
// (services/AGENTS.md §3); при печати и логировании ключ скрыт.
type Credentials struct {
	ClientID string
	APIKey   string
}

const redacted = "[redacted]"

func (c Credentials) String() string {
	return "ozon.Credentials{ClientID:" + c.ClientID + ", APIKey:" + redacted + "}"
}

// GoString закрывает %#v.
func (c Credentials) GoString() string { return c.String() }

// LogValue закрывает slog: ключ не уходит в лог даже при slog.Any("creds", c).
func (c Credentials) LogValue() slog.Value {
	return slog.GroupValue(slog.String("client_id", c.ClientID), slog.String("api_key", redacted))
}

// MarshalJSON закрывает вложенную сериализацию: slog.JSONHandler и
// encoding/json не вызывают LogValue у полей структуры.
func (c Credentials) MarshalJSON() ([]byte, error) {
	return json.Marshal(map[string]string{"client_id": c.ClientID, "api_key": redacted})
}
