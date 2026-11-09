package quota

import (
	"encoding/json"
	"math"
	"strings"
	"testing"
	"time"
)

var fixtureNow = time.Date(2026, 11, 9, 12, 0, 0, 0, time.UTC)

func fixture() Passport {
	return Passport{Version: 1, Revision: "synthetic-test", ScopeID: "0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50", WindowSeconds: 86400, Limit: 1000, Reserve: 100,
		ValidFrom: fixtureNow.Add(-time.Hour), ValidUntil: fixtureNow.Add(time.Hour), ScopeEvidence: "synthetic scope", ResetEvidence: "synthetic window", BootstrapEvidence: "synthetic coverage", ConsumersConfirmed: true,
		Shares: []Share{{"ads", 500}, {"php", 200}, {"fetcher", 200}}, Accounts: []Account{{CompanyID: "0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a51", AccountID: "0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a52", PollSeconds: 600, CycleRequests: 3, OAuthMax: 48, RetryMax: 10, OtherMax: 7}}}
}
func TestCapacityAndBoundary(t *testing.T) {
	p := fixture()
	r, err := p.Check(fixtureNow)
	// 144 regular cycles + 1 boundary = 435, plus 65 auxiliary calls.
	if err != nil || !r.PassportReady || r.AdsRequestsMax != 500 || !r.RequiresLiveAdmission {
		t.Fatal(r, err)
	}
	p.ClockSkewSeconds = 5
	r, err = p.Check(fixtureNow)
	if err != nil || r.PassportReady || r.AdsRequestsMax != 503 || len(r.Blockers) != 1 || r.Blockers[0] != "ads_capacity_exceeded" {
		t.Fatal(r, err)
	}
	p.Accounts[0].PollSeconds = 1200
	r, err = p.Check(fixtureNow)
	if err != nil || !r.PassportReady || r.AdsRequestsMax != 287 {
		t.Fatal(r, err)
	}
}
func TestIncompleteEvidenceNeverAdmits(t *testing.T) {
	p := fixture()
	p.ScopeEvidence = ""
	p.ResetEvidence = ""
	p.BootstrapEvidence = ""
	p.ConsumersConfirmed = false
	r, err := p.Check(fixtureNow)
	if err != nil || r.PassportReady || len(r.Blockers) != 4 {
		t.Fatal(r, err)
	}
	r, err = fixture().Check(fixtureNow.Add(time.Hour))
	if err != nil || r.PassportReady || len(r.Blockers) != 1 || r.Blockers[0] != "expired" {
		t.Fatal(r, err)
	}
	r, err = fixture().Check(fixtureNow.Add(-2 * time.Hour))
	if err != nil || r.PassportReady || r.Blockers[0] != "not_yet_valid" {
		t.Fatal(r, err)
	}
}
func TestMalformedAllocationsAndOverflow(t *testing.T) {
	cases := map[string]func(*Passport){
		"overallocated":      func(p *Passport) { p.Shares[0].Requests = 501 },
		"negative":           func(p *Passport) { p.Reserve = -1 },
		"missing consumer":   func(p *Passport) { p.Shares[1].Consumer = "other" },
		"duplicate consumer": func(p *Passport) { p.Shares[1].Consumer = "ads" },
		"unbounded cost":     func(p *Passport) { p.Accounts[0].CycleRequests = math.MaxInt64 },
		"aux overflow":       func(p *Passport) { p.Accounts[0].OtherMax = math.MaxInt64 },
		"late poll":          func(p *Passport) { p.Accounts[0].PollSeconds = 86401 },
		"unsupported limit":  func(p *Passport) { p.Limit = 100001 },
		"early poll":         func(p *Passport) { p.Accounts[0].PollSeconds = 599 },
		"duplicate account":  func(p *Passport) { p.Accounts = append(p.Accounts, p.Accounts[0]) },
		"unsupported window": func(p *Passport) { p.WindowSeconds = 86399 },
		"negative extra":     func(p *Passport) { p.Accounts[0].RetryMax = -1 },
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			p := fixture()
			mutate(&p)
			if _, err := p.Check(fixtureNow); err != ErrInvalid {
				t.Fatal(err)
			}
		})
	}
}
func TestAccountsSumInOneScope(t *testing.T) {
	p := fixture()
	second := p.Accounts[0]
	second.CompanyID = "0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a53"
	p.Accounts = append(p.Accounts, second)
	r, err := p.Check(fixtureNow)
	if err != nil || r.AdsRequestsMax != 1000 || r.PassportReady {
		t.Fatal(r, err)
	}
}
func TestStrictDocumentAndNoSecretErrors(t *testing.T) {
	raw, err := json.Marshal(fixture())
	if err != nil {
		t.Fatal(err)
	}
	p, err := Decode(strings.NewReader(string(raw)))
	if err != nil {
		t.Fatal(err)
	}
	if r, e := p.Check(fixtureNow); e != nil || !r.PassportReady {
		t.Fatal(r, e)
	}
	for _, body := range []string{
		`{"version":1,"version":2,"secret":"do-not-print"}`,
		`{"version":1,"\u0076ersion":2}`,
		string(raw) + ` {}`, strings.Replace(string(raw), `"version":1`, `"version":1.0`, 1),
		strings.Replace(string(raw), `"version":1`, `"unknown":"do-not-print","version":1`, 1),
		strings.Repeat("x", MaxDocumentBytes+1), strings.Repeat("[", 18) + strings.Repeat("]", 18),
	} {
		if _, e := Decode(strings.NewReader(body)); e != ErrInvalid {
			t.Fatal("unsafe or ambiguous input accepted", e)
		}
	}
}

func TestMissingNullAndCaseAliasesCannotBecomeImplicitZeroOrTrue(t *testing.T) {
	raw, err := json.Marshal(fixture())
	if err != nil {
		t.Fatal(err)
	}
	base := string(raw)
	for _, body := range []string{
		strings.Replace(base, `"oauth_max":48,`, "", 1),
		strings.Replace(base, `"oauth_max":48`, `"oauth_max":null`, 1),
		strings.Replace(base, `"reserve":100,`, "", 1),
		strings.Replace(base, `"clock_skew_seconds":0,`, "", 1),
		strings.Replace(base, `"requests":200`, `"requests":null`, 1),
		strings.Replace(base, `"consumers_confirmed":true`, `"consumers_confirmed":false,"CONSUMERS_CONFIRMED":true`, 1),
		strings.Replace(base, `"consumer":"ads"`, `"CONSUMER":"ads"`, 1),
		strings.Replace(base, `"poll_seconds":600`, `"POLL_SECONDS":600`, 1),
	} {
		if _, e := Decode(strings.NewReader(body)); e != ErrInvalid {
			t.Fatal("ambiguous or incomplete declarations accepted", e)
		}
	}
}
