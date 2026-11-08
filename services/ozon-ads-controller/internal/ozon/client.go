// Package ozon contains an analytics-only connector. There is no public generic
// request method and no campaign mutation endpoint, including GET mutations.
package ozon

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"
)

type Credentials struct {
	CompanyID, AccountID, ClientID, ClientSecret string
	UpdatedAt                                    time.Time
}
type Quota interface {
	Take(context.Context, string, string, time.Time) error
	AuthorizationAllowed(context.Context, string, string, time.Time) error
	RejectAuthorization(context.Context, string, string, time.Time) error
}
type ReplyError struct {
	Status     int
	RetryAfter time.Duration
}

func (e *ReplyError) Error() string { return fmt.Sprintf("ozon_http_%d", e.Status) }

type session struct {
	mu          sync.Mutex
	token       string
	expires     time.Time
	fingerprint string
}
type Client struct {
	http     *http.Client
	base     string
	quota    Quota
	now      func() time.Time
	mu       sync.Mutex
	sessions map[string]*session
}

func New(client *http.Client, base string, quota Quota, now func() time.Time) (*Client, error) {
	u, err := url.Parse(base)
	if err != nil || u.Host == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" || (u.Scheme != "https" && u.Hostname() != "127.0.0.1") {
		return nil, errors.New("invalid_ozon_url")
	}
	copyClient := *client
	copyClient.CheckRedirect = func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }
	copyClient.Timeout = 10 * time.Second
	return &Client{http: &copyClient, base: strings.TrimSuffix(base, "/"), quota: quota, now: now, sessions: make(map[string]*session)}, nil
}
func (c *Client) Forget() { c.mu.Lock(); defer c.mu.Unlock(); c.sessions = make(map[string]*session) }
func (c *Client) account(credentials Credentials) *session {
	c.mu.Lock()
	defer c.mu.Unlock()
	key := credentials.CompanyID + ":" + credentials.AccountID
	s := c.sessions[key]
	if s == nil {
		s = &session{}
		c.sessions[key] = s
	}
	return s
}
func (c *Client) request(ctx context.Context, cred Credentials, method, path, token string, body []byte) ([]byte, error) {
	// Exact allowlist is checked at the final network boundary.
	route := strings.SplitN(path, "?", 2)[0]
	allowed := (method == http.MethodPost && (route == "/api/client/token" || route == "/api/client/statistics/products/sku")) || (method == http.MethodGet && (route == "/api/client/campaign" || route == "/api/client/statistics/campaign/product/json"))
	if !allowed {
		return nil, errors.New("operation_forbidden")
	}
	if err := c.quota.Take(ctx, cred.CompanyID, cred.AccountID, c.now()); err != nil {
		return nil, err
	}
	req, err := http.NewRequestWithContext(ctx, method, c.base+path, bytes.NewReader(body))
	if err != nil {
		return nil, errors.New("invalid_request")
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Content-Type", "application/json")
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}
	resp, err := c.http.Do(req)
	if err != nil {
		return nil, errors.New("ozon_transport")
	}
	defer func() { _ = resp.Body.Close() }()
	if resp.StatusCode != http.StatusOK {
		pause := time.Duration(0)
		if resp.StatusCode == 429 {
			pause = 10 * time.Minute
			raw := resp.Header.Get("Retry-After")
			if seconds, e := strconv.ParseInt(raw, 10, 32); e == nil && seconds > 0 {
				pause = time.Duration(seconds) * time.Second
			} else if date, e := http.ParseTime(raw); e == nil && date.After(c.now()) {
				pause = date.Sub(c.now())
			}
		}
		return nil, &ReplyError{Status: resp.StatusCode, RetryAfter: pause}
	}
	if !strings.HasPrefix(strings.ToLower(resp.Header.Get("Content-Type")), "application/json") {
		return nil, errors.New("invalid_content_type")
	}
	data, err := io.ReadAll(io.LimitReader(resp.Body, 8*1024*1024+1))
	if err != nil || len(data) > 8*1024*1024 {
		return nil, errors.New("response_too_large_or_unreadable")
	}
	return data, nil
}
func (c *Client) token(ctx context.Context, cred Credentials, s *session) error {
	if s.token != "" && c.now().Before(s.expires) && s.fingerprint == cred.ClientID+":"+cred.ClientSecret {
		return nil
	}
	// Credentials and tokens live only in this account's in-memory session.
	s.token = ""
	s.fingerprint = ""
	//nolint:gosec // G117: OAuth requires this secret in the request body; it is never logged or persisted.
	body, err := json.Marshal(struct {
		ID     string `json:"client_id"`
		Secret string `json:"client_secret"`
		Grant  string `json:"grant_type"`
	}{cred.ClientID, cred.ClientSecret, "client_credentials"})
	if err != nil {
		return errors.New("invalid_credentials")
	}
	data, err := c.request(ctx, cred, http.MethodPost, "/api/client/token", "", body)
	if err != nil {
		return err
	}
	var response struct {
		Token   string `json:"access_token"`
		Expires int    `json:"expires_in"`
		Type    string `json:"token_type"`
	}
	if json.Unmarshal(data, &response) != nil || response.Token == "" || len(response.Token) > 16384 || response.Expires <= 60 || response.Expires > 86400 || !strings.EqualFold(response.Type, "Bearer") {
		return errors.New("invalid_token_response")
	}
	s.token = response.Token
	s.expires = c.now().Add(time.Duration(response.Expires-60) * time.Second)
	s.fingerprint = cred.ClientID + ":" + cred.ClientSecret
	return nil
}
func (c *Client) read(ctx context.Context, cred Credentials, method, path string, body []byte) ([]byte, error) {
	if cred.CompanyID == "" || cred.AccountID == "" || cred.ClientID == "" || cred.ClientSecret == "" || cred.UpdatedAt.IsZero() {
		return nil, errors.New("missing_credentials")
	}
	s := c.account(cred)
	s.mu.Lock()
	defer s.mu.Unlock()
	if err := c.quota.AuthorizationAllowed(ctx, cred.CompanyID, cred.AccountID, cred.UpdatedAt); err != nil {
		return nil, err
	}
	reject := func(err error) error {
		var denied *ReplyError
		if errors.As(err, &denied) && (denied.Status == 401 || denied.Status == 403) {
			s.token = ""
			if blockErr := c.quota.RejectAuthorization(ctx, cred.CompanyID, cred.AccountID, cred.UpdatedAt); blockErr != nil {
				return errors.New("authorization_state_unavailable")
			}
		}
		return err
	}
	if err := c.token(ctx, cred, s); err != nil {
		return nil, reject(err)
	}
	data, err := c.request(ctx, cred, method, path, s.token, body)
	var denied *ReplyError
	if errors.As(err, &denied) && denied.Status == 401 {
		s.token = ""
		if refresh := c.token(ctx, cred, s); refresh != nil {
			return nil, reject(refresh)
		}
		data, err = c.request(ctx, cred, method, path, s.token, body)
	}
	return data, reject(err)
}

func (c *Client) Campaigns(ctx context.Context, cred Credentials) ([]byte, error) {
	return c.read(ctx, cred, http.MethodGet, "/api/client/campaign", nil)
}
func (c *Client) Aggregate(ctx context.Context, cred Credentials, day string) ([]byte, error) {
	if _, err := time.Parse(time.DateOnly, day); err != nil {
		return nil, errors.New("invalid_day")
	}
	query := url.Values{"dateFrom": {day}, "dateTo": {day}}
	return c.read(ctx, cred, http.MethodGet, "/api/client/statistics/campaign/product/json?"+query.Encode(), nil)
}
func (c *Client) SKU(ctx context.Context, cred Credentials, day string, ids []string) ([]byte, error) {
	if _, err := time.Parse(time.DateOnly, day); err != nil || len(ids) < 1 || len(ids) > 10 {
		return nil, errors.New("invalid_scope")
	}
	body, err := json.Marshal(struct {
		Campaigns []string `json:"campaignIds"`
		From      string   `json:"dateFrom"`
		To        string   `json:"dateTo"`
	}{ids, day, day})
	if err != nil {
		return nil, err
	}
	return c.read(ctx, cred, http.MethodPost, "/api/client/statistics/products/sku", body)
}
