package observation

import (
	"encoding/json"
	"errors"
)

type Campaign struct{ ID, State, PaymentType, ObjectType string }

func ParseCampaigns(body []byte) (map[string]Campaign, error) {
	var response struct {
		List []struct {
			ID      string `json:"id"`
			State   string `json:"state"`
			Payment string `json:"PaymentType"`
			Object  string `json:"advObjectType"`
		} `json:"list"`
	}
	if json.Unmarshal(body, &response) != nil || response.List == nil {
		return nil, errors.New("invalid_campaign_list")
	}
	campaigns := map[string]Campaign{}
	for _, r := range response.List {
		if !ValidCampaignID(r.ID) {
			return nil, errors.New("invalid_campaign_id")
		}
		if _, exists := campaigns[r.ID]; exists {
			return nil, errors.New("duplicate_campaign")
		}
		campaigns[r.ID] = Campaign{r.ID, r.State, r.Payment, r.Object}
	}
	return campaigns, nil
}
