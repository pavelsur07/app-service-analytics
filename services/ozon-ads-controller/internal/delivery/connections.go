package delivery

import (
	"bytes"
	"encoding/json"
	"errors"
	"regexp"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/observation"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/ozon"
)

// Every key is mandatory, including nullable keys. Case folding by encoding/json
// must not silently extend the published contract.
func strictObject(raw []byte, keys []string, target any) error {
	var fields map[string]json.RawMessage
	if json.Unmarshal(raw, &fields) != nil || len(fields) != len(keys) {
		return errors.New("contract_violation")
	}
	for _, key := range keys {
		if _, ok := fields[key]; !ok {
			return errors.New("contract_violation")
		}
	}
	if json.Unmarshal(raw, target) != nil {
		return errors.New("contract_violation")
	}
	return nil
}

var dateTimeForm = regexp.MustCompile(`^(\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:)(\d{2})(\.\d+)?([Zz]|[+-]([01]\d|2[0-3]):[0-5]\d)$`)

func parseDate(raw string) (time.Time, error) {
	m := dateTimeForm.FindStringSubmatch(raw)
	if m == nil {
		return time.Time{}, errors.New("contract_violation")
	}
	if m[2] == "60" {
		m[2] = "59"
	}
	return time.Parse(time.RFC3339, strings.ToUpper(m[1]+m[2]+m[3]+m[4]))
}

type connectionsPage struct {
	Connections []ozon.Credentials
	Next        *string
}

func parseConnections(raw []byte) (connectionsPage, error) {
	var page struct {
		Schema      string            `json:"schema_version"`
		At          string            `json:"generated_at"`
		Connections []json.RawMessage `json:"connections"`
		Next        *string           `json:"next_cursor"`
	}
	fail := errors.New("contract_violation")
	if strictObject(raw, []string{"schema_version", "generated_at", "connections", "next_cursor"}, &page) != nil || page.Schema != "1" || page.Connections == nil || len(page.Connections) > 100 {
		return connectionsPage{}, fail
	}
	if _, err := parseDate(page.At); err != nil {
		return connectionsPage{}, fail
	}
	result := connectionsPage{Next: page.Next, Connections: []ozon.Credentials{}}
	for _, row := range page.Connections {
		var w struct {
			CompanyID   string          `json:"company_id"`
			AccountID   string          `json:"marketplace_account_id"`
			Provider    string          `json:"provider"`
			At          string          `json:"updated_at"`
			Credentials json.RawMessage `json:"credentials"`
			Resources   *[]string       `json:"resource_types"`
		}
		if strictObject(row, []string{"company_id", "marketplace_account_id", "provider", "updated_at", "credentials", "resource_types"}, &w) != nil || !observation.ValidUUID(w.CompanyID) || !observation.ValidUUID(w.AccountID) || w.Provider != "ozon" || w.Resources == nil {
			return connectionsPage{}, fail
		}
		at, err := parseDate(w.At)
		if err != nil {
			return connectionsPage{}, fail
		}
		for _, resource := range *w.Resources {
			if resource == "" {
				return connectionsPage{}, fail
			}
		}
		var pair struct {
			Seller      json.RawMessage `json:"seller"`
			Performance json.RawMessage `json:"performance"`
		}
		if strictObject(w.Credentials, []string{"seller", "performance"}, &pair) != nil {
			return connectionsPage{}, fail
		}
		if !bytes.Equal(bytes.TrimSpace(pair.Seller), []byte("null")) {
			var seller struct {
				ID  string `json:"client_id"`
				Key string `json:"api_key"`
			}
			if strictObject(pair.Seller, []string{"client_id", "api_key"}, &seller) != nil || seller.ID == "" || seller.Key == "" {
				return connectionsPage{}, fail
			}
		}
		cred := ozon.Credentials{CompanyID: w.CompanyID, AccountID: w.AccountID, UpdatedAt: at}
		if !bytes.Equal(bytes.TrimSpace(pair.Performance), []byte("null")) {
			var perf struct {
				ID     string `json:"client_id"`
				Secret string `json:"client_secret"`
			}
			if strictObject(pair.Performance, []string{"client_id", "client_secret"}, &perf) != nil || perf.ID == "" || perf.Secret == "" {
				return connectionsPage{}, fail
			}
			cred.ClientID = perf.ID
			cred.ClientSecret = perf.Secret
		}
		result.Connections = append(result.Connections, cred)
	}
	return result, nil
}
