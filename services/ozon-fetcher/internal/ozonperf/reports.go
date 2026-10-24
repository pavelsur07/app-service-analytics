package ozonperf

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/ozon"
)

// Асинхронные отчёты по товарам кампаний
// (docs/tasks/marketplace-data-ingestion/stages/ozon-7-ads-reports.md §2).
const (
	EndpointStatistics       = "/api/client/statistics"
	EndpointStatisticsStatus = "/api/client/statistics/{UUID}"
	EndpointStatisticsReport = "/api/client/statistics/report"

	// MaxReportCampaigns — предел Ozon («Превышен лимит по количеству кампаний (максимум 10)»).
	MaxReportCampaigns = 10
)

// ReportState — состояние отчёта Ozon.
type ReportState string

const (
	ReportPending ReportState = "pending" // NOT_STARTED, IN_PROGRESS
	ReportReady   ReportState = "ready"   // OK, READY
	ReportFailed  ReportState = "failed"  // ERROR, CANCELLED, NOT_FOUND
)

// ReportStatus — состояние и ссылка на файл готового отчёта.
type ReportStatus struct {
	State ReportState
	Raw   string // state Ozon как пришёл
	Link  string
}

// OrderReport заказывает отчёт по кампаниям за дни [from; to] МСК с
// разбивкой по дням. Возвращает UUID отчёта.
func (c *Client) OrderReport(ctx context.Context, ref account.Ref, creds Credentials, campaigns []string, from, to time.Time) (string, error) {
	if len(campaigns) == 0 || len(campaigns) > MaxReportCampaigns {
		return "", fmt.Errorf("ozonperf: report needs 1..%d campaigns, got %d", MaxReportCampaigns, len(campaigns))
	}
	// День МСК — от полуночи до 23:59:59 в UTC, как Symfony.
	start := time.Date(from.Year(), from.Month(), from.Day(), 0, 0, 0, 0, from.Location()).UTC()
	end := time.Date(to.Year(), to.Month(), to.Day(), 23, 59, 59, 0, to.Location()).UTC()
	resp, err := c.Post(ctx, ref, creds, EndpointStatistics, map[string]any{
		"campaigns": campaigns,
		"from":      start.Format(time.RFC3339),
		"to":        end.Format(time.RFC3339),
		"groupBy":   "DATE",
	})
	if err != nil {
		return "", err
	}
	var body struct {
		UUID  string `json:"UUID"`
		Lower string `json:"uuid"`
	}
	_ = json.Unmarshal(resp.Body, &body)
	id := strings.TrimSpace(body.UUID)
	if id == "" {
		id = strings.TrimSpace(body.Lower)
	}
	if id == "" || strings.ContainsAny(id, "/?#") {
		return "", invalid(EndpointStatistics, resp.Status, "response has no report UUID")
	}
	return id, nil
}

// ReportStatus — состояние отчёта по UUID.
func (c *Client) ReportStatus(ctx context.Context, ref account.Ref, creds Credentials, id string) (ReportStatus, error) {
	resp, err := c.getPath(ctx, ref, creds, EndpointStatisticsStatus, "/api/client/statistics/"+url.PathEscape(id), nil)
	var apiErr *ozon.APIError
	if errors.As(err, &apiErr) && apiErr.Status == http.StatusNotFound && apiErr.Endpoint == EndpointStatisticsStatus {
		// Отчёт пропал — как ошибка формирования: новый заказ, 3 подряд — отказ.
		return ReportStatus{State: ReportFailed, Raw: "HTTP_404"}, nil
	}
	if err != nil {
		return ReportStatus{}, err
	}
	var body struct {
		State  string `json:"state"`
		Link   string `json:"link"`
		Report *struct {
			Link string `json:"link"`
		} `json:"report"`
	}
	if err := json.Unmarshal(resp.Body, &body); err != nil || body.State == "" {
		return ReportStatus{}, invalid(EndpointStatisticsStatus, resp.Status, `response has no "state"`)
	}
	st := ReportStatus{Raw: body.State, Link: strings.TrimSpace(body.Link)}
	if st.Link == "" && body.Report != nil {
		st.Link = strings.TrimSpace(body.Report.Link)
	}
	switch strings.ToUpper(body.State) {
	case "NOT_STARTED", "IN_PROGRESS":
		st.State = ReportPending
	case "OK", "READY":
		st.State = ReportReady
	case "ERROR", "CANCELLED", "NOT_FOUND":
		st.State = ReportFailed
	default:
		return ReportStatus{}, invalid(EndpointStatisticsStatus, resp.Status, "unknown report state "+body.State)
	}
	return st, nil
}

// DownloadReport скачивает готовый отчёт (ZIP или CSV) как есть. Ссылка —
// относительная от хоста Performance или абсолютная на нём же; чужой хост —
// отказ: Bearer туда не уходит. Пустая ссылка — /statistics/report?UUID=.
func (c *Client) DownloadReport(ctx context.Context, ref account.Ref, creds Credentials, id, link string) (ozon.Page, error) {
	path, query := EndpointStatisticsReport, url.Values{"UUID": {id}}
	if link != "" {
		u, err := url.Parse(link)
		if err != nil {
			return ozon.Page{}, invalid(EndpointStatisticsReport, 0, "bad report link")
		}
		if u.IsAbs() {
			base, _ := url.Parse(c.baseURL)
			if !strings.EqualFold(u.Host, base.Host) {
				return ozon.Page{}, invalid(EndpointStatisticsReport, 0, "report link points to another host")
			}
		}
		path, query = u.Path, u.Query()
	}
	resp, err := c.getPath(ctx, ref, creds, EndpointStatisticsReport, path, query)
	if err != nil {
		return ozon.Page{}, err
	}
	if len(resp.Body) == 0 {
		return ozon.Page{}, invalid(EndpointStatisticsReport, resp.Status, "empty report body")
	}
	ct := resp.Header.Get("Content-Type")
	if strings.HasPrefix(string(resp.Body), "PK\x03\x04") {
		ct = "application/zip"
	} else if ct == "" {
		ct = "text/csv"
	}
	return ozon.Page{No: 1, Body: resp.Body, ContentType: ct}, nil
}

// getPath — GET по пути (UUID в пути); endpoint — шаблон для лимитера и журнала.
func (c *Client) getPath(ctx context.Context, ref account.Ref, creds Credentials, endpoint, path string, query url.Values) (ozon.Response, error) {
	if !strings.HasPrefix(path, "/") {
		return ozon.Response{}, &ozon.APIError{Class: ozon.ClassInvalidResponse, Endpoint: endpoint, Status: http.StatusOK, Err: errors.New("bad path")}
	}
	target := c.baseURL + path
	if len(query) > 0 {
		target += "?" + query.Encode()
	}
	return c.do(ctx, ref, creds, endpoint, http.MethodGet, target, nil)
}
