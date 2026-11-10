// Package control schedules hypothetical decisions only. It has no executor.
package control

import (
	"context"
	"errors"
	"sync"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/delivery"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/observation"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/store"
)

type Reader interface {
	Campaigns(context.Context, ozon.Credentials) ([]byte, error)
	Aggregate(context.Context, ozon.Credentials, string) ([]byte, error)
	SKU(context.Context, ozon.Credentials, string, []string) ([]byte, error)
	Forget()
}
type Repository interface {
	Apply(context.Context, []observation.Policy, time.Time) error
	ClaimAccount(context.Context, string, time.Time) (*store.Claim, error)
	Finish(context.Context, string, string, string, []store.Item, time.Time, time.Time, time.Time) error
}
type activeRead struct {
	cancel      context.CancelFunc
	credentials ozon.Credentials
	policyIDs   []string
	token       string
}
type Engine struct {
	Store       Repository
	Reader      Reader
	Now         func() time.Time
	ClockOK     func(time.Time) bool
	mu          sync.RWMutex
	credentials map[string]ozon.Credentials
	active      map[string]activeRead
	grants      map[string]bool
	expires     time.Time
}

// Install changes credentials only after the complete configuration commits.
func (e *Engine) Install(ctx context.Context, c delivery.Configuration, now time.Time) error {
	e.mu.Lock()
	defer e.mu.Unlock()
	if err := e.Store.Apply(ctx, c.Policies, now); err != nil {
		return err
	}
	granted := map[string]bool{}
	for _, p := range c.Policies {
		if p.Enabled && now.Before(p.PermissionUntil) {
			granted[delivery.AccountKey(p.CompanyID, p.AccountID)+":"+p.ID] = true
		}
	}
	for key, job := range e.active {
		allowed := c.Credentials[key] == job.credentials
		for _, id := range job.policyIDs {
			if !granted[key+":"+id] {
				allowed = false
			}
		}
		if !allowed {
			job.cancel()
		}
	}
	changed := len(e.credentials) != len(c.Credentials)
	for key, cred := range c.Credentials {
		if e.credentials[key] != cred {
			changed = true
			break
		}
	}
	if changed {
		e.Reader.Forget()
	}
	e.credentials = c.Credentials
	e.grants = granted
	e.expires = now.Add(120 * time.Second)
	return nil
}
func (e *Engine) credential(companyID, accountID string, now time.Time) (ozon.Credentials, bool) {
	e.mu.Lock()
	defer e.mu.Unlock()
	if !now.Before(e.expires) {
		e.credentials = nil
		e.Reader.Forget()
		return ozon.Credentials{}, false
	}
	cred, ok := e.credentials[delivery.AccountKey(companyID, accountID)]
	return cred, ok && cred.CompanyID == companyID && cred.AccountID == accountID
}

// Process runs under the persisted account lease. Schedule boundaries use cached
// observations; they do not trigger an early statistics request.
func (e *Engine) Process(ctx context.Context, c *store.Claim) error {
	now := e.Now()
	if e.ClockOK != nil && !e.ClockOK(now) {
		return ErrClockSkew
	}
	cred, allowed := e.credential(c.CompanyID, c.AccountID, now)
	polling := !now.Before(c.NextPoll)
	nextPoll := c.NextPoll
	var primary, sku map[string]observation.Snapshot
	var campaigns map[string]observation.Campaign
	readError := ""
	interval := 86400
	ids := []string{}
	for _, item := range c.Items {
		p := item.Policy
		if p.Enabled && now.Before(p.PermissionUntil) {
			if p.PollSeconds < interval {
				interval = p.PollSeconds
			}
			ids = append(ids, p.CampaignID)
		}
	}
	if polling {
		nextPoll = now.Add(time.Duration(interval) * time.Second)
		if allowed && len(ids) > 0 {
			readCtx, stopRead := e.beginRead(ctx, c, cred)
			defer stopRead()
			day, _ := c.Items[0].Policy.Window(now)
			var err error
			var campaignBody []byte
			err = readCtx.Err()
			if err == nil {
				campaignBody, err = e.Reader.Campaigns(readCtx, cred)
			}
			if err == nil {
				campaigns, err = observation.ParseCampaigns(campaignBody)
			}
			var body []byte
			if err == nil {
				err = readCtx.Err()
				if err == nil {
					body, err = e.Reader.Aggregate(readCtx, cred, day)
				}
			}
			if err == nil {
				primary, err = observation.ParseAggregate(body, day, e.Now())
			}
			if err == nil {
				err = readCtx.Err()
				if err == nil {
					body, err = e.Reader.SKU(readCtx, cred, day, ids)
				}
			}
			if err == nil {
				sku, err = observation.ParseSKU(body, ids, day, e.Now())
			}
			if errors.Is(err, ErrClockSkew) {
				return err
			}
			if err != nil {
				readError = "source_unavailable"
				sku = nil
				var limited *ozon.ReplyError
				if errors.As(err, &limited) && limited.Status == 429 {
					readError = "rate_limited"
					if retry := e.Now().Add(limited.RetryAfter); retry.After(nextPoll) {
						nextPoll = retry
					}
				}
			}
		} else {
			readError = "permission_or_credentials_unavailable"
		}
	}
	now = e.Now()
	if e.ClockOK != nil && !e.ClockOK(now) {
		return ErrClockSkew
	}
	nextTick := nextPoll
	for index := range c.Items {
		item := &c.Items[index]
		p := item.Policy
		r := &item.Runtime
		day, _ := p.Window(now)
		if r.Day != day {
			*r = store.Runtime{Day: day}
		}
		if polling {
			r.Error = readError
			r.Primary = observation.Snapshot{}
			r.SKU = nil
			if value, ok := primary[p.CampaignID]; ok {
				r.Primary = value
			}
			if value, ok := sku[p.CampaignID]; ok {
				r.SKU = &value
			}
			if readError != "" {
				r.Primary.Complete = false
			}
			campaign, exists := campaigns[p.CampaignID]
			if readError == "" && (!exists || campaign.PaymentType != "CPC" || campaign.ObjectType != "SKU") {
				r.Error = "campaign_unsupported_or_missing"
				r.Primary.Complete = false
			}
			if exists && campaign.State != "CAMPAIGN_STATE_RUNNING" && campaign.State != "CAMPAIGN_STATE_INACTIVE" {
				r.Error = "campaign_state_blocked"
				r.Primary.Complete = false
			}
			// Paused campaigns are never proposed for automatic resumption in observe.
			if exists && campaign.State == "CAMPAIGN_STATE_INACTIVE" {
				r.Error = "manual_pause"
				r.Primary.Complete = false
			}
			// Financial observations survive a failed cross-check; quality still
			// blocks the decision. Only this exact CPC campaign/reporting day.
			if exists && campaign.PaymentType == "CPC" && campaign.ObjectType == "SKU" {
				for _, snapshot := range []*observation.Snapshot{&r.Primary, r.SKU} {
					if snapshot != nil && snapshot.CampaignID == p.CampaignID && snapshot.Day == day && snapshot.Spend.Currency == "RUB" && snapshot.Spend.Minor >= 0 {
						if r.Effective == nil || snapshot.Spend.Minor > r.Effective.Minor {
							value := snapshot.Spend
							r.Effective = &value
						}
					}
				}
			}

		}
		decision := observation.Evaluate(p, now, r.Primary, r.SKU, r.Effective, nil)
		if decision.Action == "blocked" && decision.Reason == "primary_unavailable" && r.Error != "" {
			decision.Reason = r.Error
		}
		if r.Latched && decision.Action != "would_stop" {
			decision.Action = "blocked"
			decision.Reason = "protective_latch"
		}
		if decision.Action == "would_stop" && (decision.Reason == "budget_reached" || decision.Reason == "budget_margin") {
			r.Latched = true
		}
		if decision.Effective != nil {
			r.Effective = decision.Effective
		}
		r.Decision = decision
		r.AppliedVersion = p.Version
		r.EvaluatedAt = now.UTC().Truncate(time.Second)
		for _, boundary := range Boundaries(p, now) {
			if boundary.After(now) && boundary.Before(nextTick) {
				nextTick = boundary
			}
		}
	}
	if !nextTick.After(now) {
		nextTick = now.Add(time.Second)
	}
	checkedAt := e.Now()
	if e.ClockOK != nil && !e.ClockOK(checkedAt) {
		return ErrClockSkew
	}
	if err := e.Store.Finish(ctx, c.CompanyID, c.AccountID, c.Token, c.Items, nextPoll, nextTick, checkedAt); err != nil {
		return err
	}
	// Publication is an eventually consistent projection, never a prerequisite for
	// the next local deadline. A separate bounded publisher replays current state.
	return nil
}
func Boundaries(p observation.Policy, now time.Time) []time.Time {
	local := now.In(time.FixedZone("Europe/Moscow", 10800))
	day := local.Format(time.DateOnly)
	result := []time.Time{p.PermissionUntil, time.Date(local.Year(), local.Month(), local.Day()+1, 0, 0, 0, 0, local.Location())}
	tomorrow := time.Date(local.Year(), local.Month(), local.Day()+1, 0, 0, 0, 0, local.Location())
	result[1] = tomorrow
	for _, clock := range []string{p.Start, p.Stop} {
		at, err := time.ParseInLocation("2006-01-02 15:04", day+" "+clock, local.Location())
		if err == nil {
			result = append(result, at)
		}
	}
	return result
}

// Register under the same lock as Install so a successful revocation cancels
// in-flight HTTP and all subsequent reads of that cycle before it returns.
func (e *Engine) beginRead(ctx context.Context, c *store.Claim, cred ozon.Credentials) (context.Context, context.CancelFunc) {
	e.mu.Lock()
	defer e.mu.Unlock()
	key := delivery.AccountKey(c.CompanyID, c.AccountID)
	deadline := e.expires
	ids := []string{}
	for _, item := range c.Items {
		if item.Policy.Enabled {
			ids = append(ids, item.Policy.ID)
			if item.Policy.PermissionUntil.Before(deadline) {
				deadline = item.Policy.PermissionUntil
			}
		}
	}
	checkedAt := e.Now()
	readCtx, cancel := context.WithTimeout(ctx, deadline.Sub(checkedAt))
	allowed := e.credentials[key] == cred && checkedAt.Before(deadline)
	for _, id := range ids {
		if !e.grants[key+":"+id] {
			allowed = false
		}
	}
	if !allowed {
		cancel()
		return readCtx, cancel
	}
	if e.active == nil {
		e.active = map[string]activeRead{}
	}
	e.active[key] = activeRead{cancel: cancel, credentials: cred, policyIDs: ids, token: c.Token}
	return readCtx, func() {
		cancel()
		e.mu.Lock()
		defer e.mu.Unlock()
		if e.active[key].token == c.Token {
			delete(e.active, key)
		}
	}
}
