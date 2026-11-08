package observation

import (
	"bytes"
	"encoding/csv"
	"encoding/json"
	"errors"
	"io"
	"strings"
	"time"
)

type AggregateRow struct {
	ID         string `json:"id"`
	ObjectType string `json:"objectType"`
	Status     string `json:"status"`
	MoneySpent string `json:"moneySpent"`
}

func ParseAggregate(body []byte, day string, at time.Time) (map[string]Snapshot, error) {
	if _, err := time.Parse(time.DateOnly, day); err != nil {
		return nil, errors.New("invalid_day")
	}
	var envelope struct {
		Rows []AggregateRow `json:"rows"`
	}
	if err := json.Unmarshal(body, &envelope); err != nil || envelope.Rows == nil {
		return nil, errors.New("invalid_aggregate")
	}
	result := make(map[string]Snapshot, len(envelope.Rows))
	for _, row := range envelope.Rows {
		if !ValidCampaignID(row.ID) || row.ObjectType != "SKU" {
			return nil, errors.New("invalid_campaign")
		}
		if _, duplicate := result[row.ID]; duplicate {
			return nil, errors.New("duplicate_campaign")
		}
		money, err := ParseMoney(row.MoneySpent, "RUB")
		if err != nil {
			return nil, err
		}
		result[row.ID] = Snapshot{CampaignID: row.ID, Day: day, Spend: money, FetchedAt: at, Source: "aggregate", Complete: true}
	}
	return result, nil
}

// ParseSKU rejects duplicate natural keys instead of silently doubling spend.
// Coverage requires at least one row per requested campaign and exact scope.
// Removed products/undocumented pagination remain a documented release gate.
func ParseSKU(body []byte, requested []string, day string, at time.Time) (map[string]Snapshot, error) {
	if len(requested) == 0 || len(requested) > 10 {
		return nil, errors.New("invalid_campaign_batch")
	}
	allowed := make(map[string]bool, len(requested))
	for _, id := range requested {
		if !ValidCampaignID(id) || allowed[id] {
			return nil, errors.New("invalid_campaign_batch")
		}
		allowed[id] = true
	}
	var envelope struct {
		Rows []struct {
			CampaignID string `json:"campaignId"`
			Day        string `json:"date"`
			SKU        string `json:"sku"`
			Expense    string `json:"expense"`
		} `json:"rows"`
	}
	if json.Unmarshal(body, &envelope) != nil || envelope.Rows == nil {
		return nil, errors.New("invalid_sku")
	}
	result := make(map[string]Snapshot)
	seen := make(map[string]bool)
	for _, row := range envelope.Rows {
		if !allowed[row.CampaignID] || row.Day != day || !ValidCampaignID(row.SKU) {
			return nil, errors.New("sku_scope_mismatch")
		}
		key := row.CampaignID + ":" + row.SKU
		if seen[key] {
			return nil, errors.New("duplicate_sku")
		}
		seen[key] = true
		money, err := ParseMoney(row.Expense, "RUB")
		if err != nil {
			return nil, err
		}
		snapshot, exists := result[row.CampaignID]
		if !exists {
			snapshot = Snapshot{CampaignID: row.CampaignID, Day: day, Spend: Money{Currency: "RUB"}, FetchedAt: at, Source: "sku", Complete: true}
		}
		snapshot.Spend, err = snapshot.Spend.Add(money)
		if err != nil {
			return nil, err
		}
		result[row.CampaignID] = snapshot
	}
	for _, id := range requested {
		if _, exists := result[id]; !exists {
			return nil, errors.New("sku_coverage_unknown")
		}
	}
	return result, nil
}

// CSVData parses the observed async layout: BOM, report title, header, rows and
// a total. The total is metadata, never another SKU fact.
func CSVData(body []byte) ([][]string, error) {
	reader := csv.NewReader(bytes.NewReader(bytes.TrimPrefix(body, []byte{0xef, 0xbb, 0xbf})))
	reader.Comma = ';'
	reader.FieldsPerRecord = -1
	var result [][]string
	headerSeen := false
	for {
		row, err := reader.Read()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return nil, errors.New("invalid_csv")
		}
		if len(row) == 0 {
			continue
		}
		if row[0] == "День" {
			headerSeen = true
			continue
		}
		if !headerSeen || strings.TrimSpace(row[0]) == "Всего" {
			continue
		}
		if len(row) < 10 {
			return nil, errors.New("invalid_csv_row")
		}
		result = append(result, row)
	}
	if !headerSeen {
		return nil, errors.New("missing_csv_header")
	}
	return result, nil
}
