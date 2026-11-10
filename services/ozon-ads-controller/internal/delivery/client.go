package delivery

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/observation"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/store"
)

type Client struct {
	HTTP        *http.Client
	Base, Token string
}
type Configuration struct {
	Policies    []observation.Policy
	Credentials map[string]ozon.Credentials
}

func AccountKey(companyID, accountID string) string { return companyID + ":" + accountID }
func (c Client) request(ctx context.Context, method, path string, input, output any) error {
	var body []byte
	var err error
	if input != nil {
		body, err = json.Marshal(input)
		if err != nil {
			return err
		}
	}
	req, err := http.NewRequestWithContext(ctx, method, c.Base+path, bytes.NewReader(body))
	if err != nil {
		return errors.New("delivery_request_invalid")
	}
	req.Header.Set("Authorization", "Bearer "+c.Token)
	req.Header.Set("Content-Type", "application/json")
	client := *c.HTTP
	client.Timeout = 10 * time.Second
	client.CheckRedirect = func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }
	resp, err := client.Do(req)
	if err != nil {
		return errors.New("delivery_transport")
	}
	defer func() { _ = resp.Body.Close() }()
	if resp.StatusCode != 200 {
		return errors.New("delivery_rejected")
	}
	raw, err := io.ReadAll(io.LimitReader(resp.Body, 4*1024*1024+1))
	if err != nil || len(raw) > 4*1024*1024 {
		return errors.New("delivery_size")
	}
	if output != nil {
		if strings.HasPrefix(path, "/api/internal/ozon-ads/policies") {
			return strictObject(raw, []string{"schema_version", "policies", "next_cursor"}, output)
		}
		decoder := json.NewDecoder(bytes.NewReader(raw))
		if strings.HasPrefix(path, "/api/internal/ozon-ads/") {
			decoder.DisallowUnknownFields()
		}
		if decoder.Decode(output) != nil {
			return errors.New("contract_violation")
		}
	}
	return nil
}

// Full scans are bounded and restarted from the beginning on each refresh.
// Incomplete or repeated pages never replace the active configuration.
func (c Client) Fetch(ctx context.Context, now time.Time) (Configuration, error) {
	result := Configuration{Policies: []observation.Policy{}, Credentials: map[string]ozon.Credentials{}}
	cursor := ""
	seen := map[string]bool{}
	wanted := map[string]bool{}
	for pages := 0; ; pages++ {
		if pages >= 100 {
			return Configuration{}, errors.New("configuration_capacity")
		}
		var page struct {
			Schema   string               `json:"schema_version"`
			Policies []observation.Policy `json:"policies"`
			Next     *string              `json:"next_cursor"`
		}
		path := "/api/internal/ozon-ads/policies?limit=100"
		if cursor != "" {
			path += "&cursor=" + url.QueryEscape(cursor)
		}
		if err := c.request(ctx, http.MethodGet, path, nil, &page); err != nil {
			return Configuration{}, err
		}
		if page.Schema != "1" || page.Policies == nil || len(page.Policies) > 100 {
			return Configuration{}, errors.New("contract_violation")
		}
		for _, p := range page.Policies {
			key := AccountKey(p.CompanyID, p.AccountID) + ":" + p.ID
			if p.Validate() != nil || seen[key] || !now.Before(p.PermissionUntil) {
				return Configuration{}, errors.New("contract_violation")
			}
			if capUntil := now.Add(120 * time.Second); p.PermissionUntil.After(capUntil) {
				p.PermissionUntil = capUntil
			}
			seen[key] = true
			wanted[AccountKey(p.CompanyID, p.AccountID)] = true
			result.Policies = append(result.Policies, p)
		}
		if page.Next == nil {
			break
		}
		if !observation.ValidUUID(*page.Next) || *page.Next == cursor {
			return Configuration{}, errors.New("invalid_cursor")
		}
		cursor = *page.Next
	}
	if len(result.Policies) == 0 {
		return result, nil
	}
	cursor = ""
	connectionIDs := map[string]bool{}
	for pages := 0; ; pages++ {
		if pages >= 100 {
			return Configuration{}, errors.New("connections_capacity")
		}
		var raw json.RawMessage
		path := "/api/internal/marketplace-connections?provider=ozon&limit=100"
		if cursor != "" {
			path += "&cursor=" + url.QueryEscape(cursor)
		}
		if err := c.request(ctx, http.MethodGet, path, nil, &raw); err != nil {
			return Configuration{}, err
		}
		page, err := parseConnections(raw)
		if err != nil {
			return Configuration{}, err
		}
		for _, connection := range page.Connections {
			key := AccountKey(connection.CompanyID, connection.AccountID)
			if connectionIDs[connection.AccountID] {
				return Configuration{}, errors.New("contract_violation")
			}
			connectionIDs[connection.AccountID] = true
			if wanted[key] && connection.ClientID != "" && connection.ClientSecret != "" {
				result.Credentials[key] = connection
			}
		}
		if page.Next == nil {
			break
		}
		if *page.Next == "" || *page.Next == cursor {
			return Configuration{}, errors.New("invalid_cursor")
		}
		cursor = *page.Next
	}
	return result, nil
}
func (c Client) Publish(ctx context.Context, companyID, accountID, policyID string, runtime store.Runtime) error {
	var ack struct {
		Schema   string `json:"schema_version"`
		Accepted bool   `json:"accepted"`
	}
	err := c.request(ctx, http.MethodPost, "/api/internal/ozon-ads/observations", struct {
		Schema    string        `json:"schema_version"`
		CompanyID string        `json:"company_id"`
		AccountID string        `json:"account_id"`
		PolicyID  string        `json:"policy_id"`
		Runtime   store.Runtime `json:"runtime"`
	}{"1", companyID, accountID, policyID, runtime}, &ack)
	if err != nil {
		return err
	}
	if ack.Schema != "1" || !ack.Accepted {
		return errors.New("invalid_ack")
	}
	return nil
}
