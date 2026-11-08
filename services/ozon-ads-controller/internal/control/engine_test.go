package control

import (
	"context"
	"encoding/json"
	"fmt"
	"testing"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/delivery"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/observation"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/ozon"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/store"
)

type repository struct {
	items      []store.Item
	poll, tick time.Time
	fail       bool
}

func (r *repository) Apply(context.Context, []observation.Policy, time.Time) error {
	if r.fail {
		return fmt.Errorf("apply_failed")
	}
	return nil
}
func (r *repository) ClaimAccount(context.Context, string, time.Time) (*store.Claim, error) {
	return nil, nil
}
func (r *repository) Finish(_ context.Context, _, _, _ string, items []store.Item, poll, tick, _ time.Time) error {
	r.items = items
	r.poll = poll
	r.tick = tick
	return nil
}

type reader struct {
	calls          int
	wrong          bool
	failure        error
	skuFailure     error
	aggregateMoney string
}

func (r *reader) Forget() {}
func (r *reader) Campaigns(_ context.Context, c ozon.Credentials) ([]byte, error) {
	r.calls++
	if c.CompanyID != "019a60df-1000-7000-8000-000000000001" {
		r.wrong = true
	}
	return []byte(`{"list":[{"id":"1","state":"CAMPAIGN_STATE_RUNNING","advObjectType":"SKU","PaymentType":"CPC"}]}`), r.failure
}
func (r *reader) Aggregate(context.Context, ozon.Credentials, string) ([]byte, error) {
	r.calls++
	amount := r.aggregateMoney
	if amount == "" {
		amount = "1.03"
	}
	return []byte(fmt.Sprintf(`{"rows":[{"id":"1","objectType":"SKU","moneySpent":%q}]}`, amount)), nil
}
func (r *reader) SKU(_ context.Context, _ ozon.Credentials, day string, _ []string) ([]byte, error) {
	r.calls++
	if r.skuFailure != nil {
		return nil, r.skuFailure
	}
	return json.Marshal(struct {
		Rows []map[string]string `json:"rows"`
	}{[]map[string]string{{"campaignId": "1", "date": day, "sku": "2", "expense": "4.63"}}})
}
func setup(now time.Time) (*Engine, *store.Claim, *repository, *reader) {
	p := observation.Policy{CompanyID: "019a60df-1000-7000-8000-000000000001", AccountID: "019a60df-1000-7000-8000-000000000002", ID: "019a60df-1000-7000-8000-000000000003", CampaignID: "1", Version: 1, Mode: "observe", Enabled: true, DailyLimit: observation.Money{Minor: 400, Currency: "RUB"}, Timezone: "Europe/Moscow", Start: "09:00", Stop: "21:00", PollSeconds: 600, PermissionUntil: now.Add(120 * time.Second)}
	repo := &repository{}
	read := &reader{}
	e := &Engine{Store: repo, Reader: read, Now: func() time.Time { return now }}
	c := &store.Claim{CompanyID: p.CompanyID, AccountID: p.AccountID, Items: []store.Item{{Policy: p}}, NextPoll: now}
	if err := e.Install(context.Background(), delivery.Configuration{Policies: []observation.Policy{p}, Credentials: map[string]ozon.Credentials{delivery.AccountKey(p.CompanyID, p.AccountID): {CompanyID: p.CompanyID, AccountID: p.AccountID, ClientID: "dummy", ClientSecret: "dummy"}}}, now); err != nil {
		panic(err)
	}
	return e, c, repo, read
}
func TestPollCadenceDiscrepancyAndSchedule(t *testing.T) {
	now := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	e, c, repo, read := setup(now)
	if err := e.Process(context.Background(), c); err != nil {
		t.Fatal(err)
	}
	r := repo.items[0].Runtime
	if r.Effective == nil || r.Effective.Minor != 463 || r.Decision.Difference.Minor != 360 || !r.Latched || r.Decision.Action != "would_stop" || read.calls != 3 || read.wrong {
		t.Fatalf("%+v, calls %d", r, read.calls)
	}
	c.Items = repo.items
	c.NextPoll = repo.poll
	e.Now = func() time.Time { return now.Add(time.Second) }
	if err := e.Process(context.Background(), c); err != nil || read.calls != 3 {
		t.Fatal("early API poll", read.calls, err)
	}
	c.Items[0].Policy.DailyLimit.Minor = 1000
	if err := e.Process(context.Background(), c); err != nil || repo.items[0].Runtime.Decision.Reason != "protective_latch" {
		t.Fatal("latch lost", err)
	}
}
func TestNoCredentialsNoReadAndNewDayNotZero(t *testing.T) {
	now := time.Date(2026, 11, 8, 20, 59, 59, 0, time.UTC)
	e, c, repo, read := setup(now)
	e.credentials = nil
	if err := e.Process(context.Background(), c); err != nil || read.calls != 0 || repo.items[0].Runtime.Effective != nil {
		t.Fatal("missing credentials", err)
	}
	next := time.Date(2026, 11, 8, 21, 0, 0, 0, time.UTC)
	e.Now = func() time.Time { return next }
	c.Items = repo.items
	c.Items[0].Runtime.Effective = &observation.Money{Minor: 1000, Currency: "RUB"}
	c.NextPoll = next.Add(10 * time.Minute)
	if err := e.Process(context.Background(), c); err != nil || repo.items[0].Runtime.Day != "2026-11-09" || repo.items[0].Runtime.Effective != nil || read.calls != 0 {
		t.Fatal("new day fabricated zero", err)
	}
}
func TestRateLimitDoesNotShortenInterval(t *testing.T) {
	now := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	e, c, repo, read := setup(now)
	read.failure = &ozon.ReplyError{Status: 429, RetryAfter: time.Hour}
	if err := e.Process(context.Background(), c); err != nil {
		t.Fatal(err)
	}
	if repo.poll.Before(now.Add(time.Hour)) {
		t.Fatal(repo.poll)
	}
}
func TestBoundaryAtMoscowMidnight(t *testing.T) {
	now := time.Date(2026, 11, 8, 20, 59, 59, 0, time.UTC)
	_, c, _, _ := setup(now)
	boundaries := Boundaries(c.Items[0].Policy, now)
	want := time.Date(2026, 11, 8, 21, 0, 0, 0, time.UTC)
	found := false
	for _, at := range boundaries {
		if at.Equal(want) {
			found = true
		}
	}
	if !found {
		t.Fatal(boundaries)
	}
}

func TestFreshFractionalTimestampIsNotTreatedAsFuture(t *testing.T) {
	now := time.Date(2026, 11, 8, 10, 0, 0, 700000000, time.UTC)
	e, c, repo, _ := setup(now)
	if err := e.Process(context.Background(), c); err != nil {
		t.Fatal(err)
	}
	if repo.items[0].Runtime.Effective == nil || repo.items[0].Runtime.Effective.Minor != 463 || repo.items[0].Runtime.Decision.Action != "would_stop" {
		t.Fatal(repo.items[0].Runtime)
	}
}

type heldReader struct {
	reader
	started chan struct{}
}

func (r *heldReader) Campaigns(ctx context.Context, _ ozon.Credentials) ([]byte, error) {
	close(r.started)
	<-ctx.Done()
	return nil, ctx.Err()
}
func TestRevocationCancelsCurrentReadBeforeSubsequentRequests(t *testing.T) {
	now := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	e, c, _, _ := setup(now)
	held := &heldReader{started: make(chan struct{})}
	e.Reader = held
	done := make(chan error, 1)
	go func() { done <- e.Process(context.Background(), c) }()
	<-held.started
	if err := e.Install(context.Background(), delivery.Configuration{Policies: []observation.Policy{}, Credentials: map[string]ozon.Credentials{}}, now); err != nil {
		t.Fatal(err)
	}
	select {
	case err := <-done:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(time.Second):
		t.Fatal("revoked read still running")
	}
	if held.calls != 0 {
		t.Fatal("continued reading after revoke", held.calls)
	}
}

func TestFailedSKUStillPersistsObservedAggregateMaximum(t *testing.T) {
	now := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	e, c, repo, read := setup(now)
	read.aggregateMoney = "1.50"
	read.skuFailure = fmt.Errorf("sku unavailable")
	c.Items[0].Runtime = store.Runtime{Day: "2026-11-08", Effective: &observation.Money{Minor: 100, Currency: "RUB"}}
	if err := e.Process(context.Background(), c); err != nil {
		t.Fatal(err)
	}
	r := repo.items[0].Runtime
	if r.Effective == nil || r.Effective.Minor != 150 || r.Decision.Action != "blocked" || r.Error != "source_unavailable" {
		t.Fatal(r)
	}
}
func TestClaimCannotReadAfterPolicyWasDisabledBeforeRegistration(t *testing.T) {
	now := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	e, c, _, read := setup(now)
	disabled := c.Items[0].Policy
	disabled.Version++
	disabled.Enabled = false
	credentials := map[string]ozon.Credentials{}
	for key, value := range e.credentials {
		credentials[key] = value
	}
	if err := e.Install(context.Background(), delivery.Configuration{Policies: []observation.Policy{disabled}, Credentials: credentials}, now); err != nil {
		t.Fatal(err)
	}
	if err := e.Process(context.Background(), c); err != nil || read.calls != 0 {
		t.Fatal("disabled policy caused HTTP", read.calls, err)
	}
}
func TestDisabledPolicyDoesNotShortenActiveCadence(t *testing.T) {
	now := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	e, c, repo, _ := setup(now)
	active := c.Items[0].Policy
	active.PollSeconds = 86400
	disabled := active
	disabled.ID = "019a60df-1000-7000-8000-000000000004"
	disabled.Enabled = false
	disabled.PollSeconds = 600
	disabled.CampaignID = "2"
	c.Items = []store.Item{{Policy: active}, {Policy: disabled}}
	if err := e.Process(context.Background(), c); err != nil {
		t.Fatal(err)
	}
	if !repo.poll.Equal(now.Add(24 * time.Hour)) {
		t.Fatal("disabled cadence leaked", repo.poll)
	}
}
