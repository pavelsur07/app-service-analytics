package observation

import "time"

type Snapshot struct {
	CampaignID string    `json:"campaign_id"`
	Day        string    `json:"day"`
	Spend      Money     `json:"spend"`
	FetchedAt  time.Time `json:"fetched_at"`
	Source     string    `json:"source"`
	Complete   bool      `json:"complete"`
}

// Decision is descriptive. No type here represents an executable Ozon command.
type Decision struct {
	Action         string `json:"action"`
	Reason         string `json:"reason"`
	Effective      *Money `json:"effective_spend"`
	Difference     *Money `json:"difference"`
	Reserve        *Money `json:"reserve"`
	SourceAgeKnown bool   `json:"source_age_known"`
}

// Evaluate conservatively compares sources; reserve is nil until history AND
// the risk horizon have been accepted. A recent HTTP response is not a watermark.
func Evaluate(p Policy, now time.Time, primary Snapshot, sku *Snapshot, previous *Money, reserve *Money) Decision {
	result := Decision{Action: "blocked", Reason: "invalid_policy"}
	if p.Validate() != nil {
		return result
	}
	if !p.Enabled {
		result.Reason = "policy_disabled"
		return result
	}
	if !now.Before(p.PermissionUntil) {
		result.Reason = "permission_expired"
		return result
	}
	day, within := p.Window(now)
	knownExceeded := previous != nil && previous.Currency == p.DailyLimit.Currency && previous.Minor >= p.DailyLimit.Minor
	valid := func(s Snapshot) bool {
		return s.CampaignID == p.CampaignID && s.Day == day && s.Spend.Currency == p.DailyLimit.Currency && s.Spend.Minor >= 0 && s.Complete && !s.FetchedAt.After(now) && now.Sub(s.FetchedAt) <= time.Duration(p.PollSeconds)*time.Second+30*time.Second
	}
	if !valid(primary) {
		if knownExceeded {
			result.Action, result.Reason, result.Effective = "would_stop", "budget_reached", previous
			return result
		}
		result.Reason = "primary_unavailable"
		if !within {
			result.Action, result.Reason = "would_stop", "outside_window"
		}
		return result
	}
	effective := primary.Spend
	if sku != nil {
		if !valid(*sku) {
			if knownExceeded {
				result.Action, result.Reason, result.Effective = "would_stop", "budget_reached", previous
				return result
			}
			result.Reason = "sku_incomparable"
			return result
		}
		difference := sku.Spend.Minor - primary.Spend.Minor
		if difference < 0 {
			difference = -difference
		}
		result.Difference = &Money{Minor: difference, Currency: effective.Currency}
		if sku.Spend.Minor > effective.Minor {
			effective = sku.Spend
		}
	}
	if previous != nil {
		if previous.Currency != effective.Currency || previous.Minor < 0 {
			result.Reason = "invalid_previous_spend"
			return result
		}
		if previous.Minor > effective.Minor {
			effective = *previous
		}
	}
	result.Effective = &effective
	if !within {
		result.Action, result.Reason = "would_stop", "outside_window"
		return result
	}
	if effective.Minor >= p.DailyLimit.Minor {
		result.Action, result.Reason = "would_stop", "budget_reached"
		return result
	}
	if reserve == nil || reserve.Currency != effective.Currency || reserve.Minor < 0 {
		result.Reason = "reserve_unavailable"
		return result
	}
	result.Reserve = reserve
	// Subtraction avoids overflowing C+R when the reserve is large.
	if reserve.Minor >= p.DailyLimit.Minor-effective.Minor {
		result.Action, result.Reason = "would_stop", "budget_margin"
		return result
	}
	result.Action, result.Reason = "would_run", "conditions_met"
	return result
}
