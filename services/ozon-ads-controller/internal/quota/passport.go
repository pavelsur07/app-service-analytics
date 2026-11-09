// Package quota validates an offline allocation passport. It does not grant
// network access or assert the truth of operator-supplied evidence references.
package quota

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"math"
	"regexp"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/observation"
)

const MaxDocumentBytes = 131072

var namePattern = regexp.MustCompile(`\A[a-z][a-z0-9_-]{0,63}\z`)
var ErrInvalid = errors.New("invalid_quota_passport")

type Share struct {
	Consumer string `json:"consumer"`
	Requests int64  `json:"requests"`
}
type Account struct {
	CompanyID     string `json:"company_id"`
	AccountID     string `json:"account_id"`
	PollSeconds   int64  `json:"poll_seconds"`
	CycleRequests int64  `json:"cycle_requests"`
	OAuthMax      int64  `json:"oauth_max"`
	RetryMax      int64  `json:"retry_max"`
	OtherMax      int64  `json:"other_max"`
}
type Passport struct {
	Version            int       `json:"version"`
	Revision           string    `json:"revision"`
	ScopeID            string    `json:"scope_id"`
	WindowSeconds      int64     `json:"window_seconds"`
	ClockSkewSeconds   int64     `json:"clock_skew_seconds"`
	Limit              int64     `json:"limit"`
	Reserve            int64     `json:"reserve"`
	ValidFrom          time.Time `json:"valid_from"`
	ValidUntil         time.Time `json:"valid_until"`
	ScopeEvidence      string    `json:"scope_evidence"`
	ResetEvidence      string    `json:"reset_evidence"`
	BootstrapEvidence  string    `json:"bootstrap_evidence"`
	ConsumersConfirmed bool      `json:"consumers_confirmed"`
	Shares             []Share   `json:"shares"`
	Accounts           []Account `json:"accounts"`
}
type Result struct {
	PassportReady         bool     `json:"passport_ready"`
	RequiresLiveAdmission bool     `json:"requires_live_admission"`
	AdsRequestsMax        int64    `json:"ads_requests_max"`
	AdsShare              int64    `json:"ads_share"`
	Unallocated           int64    `json:"unallocated"`
	Blockers              []string `json:"blockers"`
}

func Decode(r io.Reader) (Passport, error) {
	var p Passport
	body, err := io.ReadAll(io.LimitReader(r, MaxDocumentBytes+1))
	if err != nil || len(body) > MaxDocumentBytes || !utf8.Valid(body) {
		return p, ErrInvalid
	}
	// encoding/json accepts duplicate keys; an allocation document must not.
	d := json.NewDecoder(bytes.NewReader(body))
	d.UseNumber()
	if err = uniqueValue(d, 0); err != nil {
		return p, ErrInvalid
	}
	if _, err = d.Token(); err != io.EOF {
		return p, ErrInvalid
	}
	d = json.NewDecoder(bytes.NewReader(body))
	d.DisallowUnknownFields()
	if err = d.Decode(&p); err != nil {
		return Passport{}, ErrInvalid
	}
	return p, nil
}
func uniqueValue(d *json.Decoder, depth int) error {
	if depth > 16 {
		return ErrInvalid
	}
	token, err := d.Token()
	if err != nil {
		return ErrInvalid
	}
	delimiter, ok := token.(json.Delim)
	if !ok {
		return nil
	}
	switch delimiter {
	case '{':
		seen := map[string]bool{}
		for d.More() {
			key, e := d.Token()
			if e != nil {
				return ErrInvalid
			}
			name, valid := key.(string)
			if !valid || seen[name] {
				return ErrInvalid
			}
			seen[name] = true
			if e = uniqueValue(d, depth+1); e != nil {
				return e
			}
		}
	case '[':
		for d.More() {
			if err = uniqueValue(d, depth+1); err != nil {
				return err
			}
		}
	default:
		return ErrInvalid
	}
	end, err := d.Token()
	if err != nil {
		return ErrInvalid
	}
	if (delimiter == '{' && end != json.Delim('}')) || (delimiter == '[' && end != json.Delim(']')) {
		return ErrInvalid
	}
	return nil
}

// Check only validates declared evidence and capacity. Missing evidence fails
// closed; evidence correctness, durable counters and live scope remain gates.
func (p Passport) Check(now time.Time) (Result, error) {
	r := Result{RequiresLiveAdmission: true, Blockers: []string{}}
	if p.Version != 1 || !namePattern.MatchString(p.Revision) || !observation.ValidUUID(p.ScopeID) ||
		p.WindowSeconds < 86400 || p.WindowSeconds > 604800 || p.ClockSkewSeconds < 0 || p.ClockSkewSeconds > 3600 ||
		p.Limit <= 0 || p.Limit > 100000 || p.Reserve < 0 || p.Reserve > p.Limit || p.ValidFrom.IsZero() || !p.ValidUntil.After(p.ValidFrom) ||
		len(p.Shares) < 3 || len(p.Shares) > 32 || len(p.Accounts) == 0 || len(p.Accounts) > 1000 {
		return r, ErrInvalid
	}
	remaining := p.Limit - p.Reserve
	seen := map[string]bool{}
	for _, s := range p.Shares {
		if !namePattern.MatchString(s.Consumer) || seen[s.Consumer] || s.Requests < 0 || s.Requests > remaining {
			return r, ErrInvalid
		}
		seen[s.Consumer] = true
		remaining -= s.Requests
		if s.Consumer == "ads" {
			r.AdsShare = s.Requests
		}
	}
	if !seen["ads"] || !seen["php"] || !seen["fetcher"] {
		return r, ErrInvalid
	}
	r.Unallocated = remaining
	accounts := map[string]bool{}
	horizon := p.WindowSeconds + 2*p.ClockSkewSeconds
	for _, a := range p.Accounts {
		key := a.CompanyID + ":" + a.AccountID
		if !observation.ValidUUID(a.CompanyID) || !observation.ValidUUID(a.AccountID) || accounts[key] || a.PollSeconds < 600 || a.PollSeconds > 86400 ||
			a.CycleRequests <= 0 || a.OAuthMax < 0 || a.RetryMax < 0 || a.OtherMax < 0 {
			return r, ErrInvalid
		}
		accounts[key] = true
		// ceil(horizon / X) + one boundary/startup cycle, no floating point.
		cycles := horizon/a.PollSeconds + 1
		if horizon%a.PollSeconds != 0 {
			cycles++
		}
		if a.CycleRequests > math.MaxInt64/cycles {
			return r, ErrInvalid
		}
		total := cycles * a.CycleRequests
		for _, n := range []int64{a.OAuthMax, a.RetryMax, a.OtherMax} {
			if n > math.MaxInt64-total {
				return r, ErrInvalid
			}
			total += n
		}
		if total > math.MaxInt64-r.AdsRequestsMax {
			return r, ErrInvalid
		}
		r.AdsRequestsMax += total
	}
	for _, e := range []struct{ value, blocker string }{
		{p.ScopeEvidence, "scope_unconfirmed"}, {p.ResetEvidence, "reset_unconfirmed"}, {p.BootstrapEvidence, "bootstrap_unconfirmed"},
	} {
		if len(e.value) > 256 {
			return r, ErrInvalid
		}
		if strings.TrimSpace(e.value) == "" {
			r.Blockers = append(r.Blockers, e.blocker)
		}
	}
	if !p.ConsumersConfirmed {
		r.Blockers = append(r.Blockers, "consumers_unconfirmed")
	}
	if now.Before(p.ValidFrom) {
		r.Blockers = append(r.Blockers, "not_yet_valid")
	}
	if !now.Before(p.ValidUntil) {
		r.Blockers = append(r.Blockers, "expired")
	}
	if r.AdsRequestsMax > r.AdsShare {
		r.Blockers = append(r.Blockers, "ads_capacity_exceeded")
	}
	r.PassportReady = len(r.Blockers) == 0
	return r, nil
}
