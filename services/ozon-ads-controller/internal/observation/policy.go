package observation

import (
	"bytes"
	"encoding/json"
	"errors"
	"regexp"
	"strconv"
	"strings"
	"time"
)

var (
	uuidPattern = regexp.MustCompile(`\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z`)
	idPattern   = regexp.MustCompile(`\A[1-9][0-9]{0,18}\z`)
)

type Policy struct {
	CompanyID       string    `json:"company_id"`
	AccountID       string    `json:"account_id"`
	ID              string    `json:"policy_id"`
	CampaignID      string    `json:"campaign_id"`
	Version         int64     `json:"version"`
	Mode            string    `json:"mode"`
	Enabled         bool      `json:"enabled"`
	DailyLimit      Money     `json:"daily_limit"`
	Timezone        string    `json:"timezone"`
	Start           string    `json:"start"`
	Stop            string    `json:"stop"`
	PollSeconds     int       `json:"poll_seconds"`
	PermissionUntil time.Time `json:"permission_until"`
}

func ValidUUID(id string) bool { return uuidPattern.MatchString(id) }

func ValidCampaignID(id string) bool {
	if !idPattern.MatchString(id) {
		return false
	}
	_, err := strconv.ParseUint(id, 10, 64)
	return err == nil
}

func clockMinutes(raw string) (int, error) {
	if len(raw) != 5 || raw[2] != ':' {
		return 0, errors.New("invalid_clock")
	}
	hour, e1 := strconv.Atoi(raw[:2])
	minute, e2 := strconv.Atoi(raw[3:])
	if e1 != nil || e2 != nil || hour < 0 || hour > 23 || minute < 0 || minute > 59 || strings.ContainsAny(raw, "+- ") {
		return 0, errors.New("invalid_clock")
	}
	return hour*60 + minute, nil
}

func (p Policy) Validate() error {
	start, errStart := clockMinutes(p.Start)
	stop, errStop := clockMinutes(p.Stop)
	if !ValidUUID(p.CompanyID) || !ValidUUID(p.AccountID) || !ValidUUID(p.ID) || !ValidCampaignID(p.CampaignID) || p.Version < 1 || p.Mode != "observe" || p.DailyLimit.Minor <= 0 || p.DailyLimit.Minor > 9007199254740991 || p.DailyLimit.Currency != "RUB" || p.Timezone != "Europe/Moscow" || errStart != nil || errStop != nil || start >= stop || p.PollSeconds < 600 || p.PollSeconds > 86400 || p.PermissionUntil.IsZero() {
		return errors.New("invalid_policy")
	}
	return nil
}

func (p Policy) Window(now time.Time) (string, bool) {
	// The first release supports the verified UTC+03 reporting day only.
	local := now.In(time.FixedZone("Europe/Moscow", 3*60*60))
	start, _ := clockMinutes(p.Start)
	stop, _ := clockMinutes(p.Stop)
	minute := local.Hour()*60 + local.Minute()
	return local.Format(time.DateOnly), minute >= start && minute < stop
}

// UnmarshalJSON enforces the wire contract, including required false/null fields.
func (p *Policy) UnmarshalJSON(raw []byte) error {
	type wirePolicy Policy
	var fields map[string]json.RawMessage
	if json.Unmarshal(raw, &fields) != nil || len(fields) != 13 {
		return errors.New("invalid_policy_contract")
	}
	for _, key := range []string{"company_id", "account_id", "policy_id", "campaign_id", "version", "mode", "enabled", "daily_limit", "timezone", "start", "stop", "poll_seconds", "permission_until"} {
		if value, ok := fields[key]; !ok || bytes.Equal(bytes.TrimSpace(value), []byte("null")) {
			return errors.New("invalid_policy_contract")
		}
	}
	var money map[string]json.RawMessage
	if json.Unmarshal(fields["daily_limit"], &money) != nil || len(money) != 2 || money["amount_minor"] == nil || money["currency"] == nil || bytes.Equal(bytes.TrimSpace(money["amount_minor"]), []byte("null")) {
		return errors.New("invalid_policy_contract")
	}
	var w wirePolicy
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.DisallowUnknownFields()
	if decoder.Decode(&w) != nil {
		return errors.New("invalid_policy_contract")
	}
	*p = Policy(w)
	return p.Validate()
}
