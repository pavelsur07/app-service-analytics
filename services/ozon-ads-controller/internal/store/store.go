package store

import (
	"context"
	"embed"
	"encoding/json"
	"errors"
	"io/fs"
	"time"

	"database/sql"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
	_ "github.com/jackc/pgx/v5/stdlib"
	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/observation"
	"github.com/pressly/goose/v3"
)

//go:embed migrations/*.sql
var migrations embed.FS

func migrationProvider(dsn string) (*goose.Provider, *sql.DB, error) {
	db, err := sql.Open("pgx", dsn)
	if err != nil {
		return nil, nil, err
	}
	source, err := fs.Sub(migrations, "migrations")
	if err != nil {
		_ = db.Close()
		return nil, nil, err
	}
	provider, err := goose.NewProvider(goose.DialectPostgres, db, source)
	if err != nil {
		_ = db.Close()
		return nil, nil, err
	}
	return provider, db, nil
}
func Migrate(ctx context.Context, dsn string, down bool) error {
	provider, db, err := migrationProvider(dsn)
	if err != nil {
		return err
	}
	defer func() { _ = db.Close() }()
	if down {
		_, err = provider.Down(ctx)
	} else {
		_, err = provider.Up(ctx)
	}
	return err
}
func MigrationsPending(ctx context.Context, dsn string) (bool, error) {
	provider, db, err := migrationProvider(dsn)
	if err != nil {
		return false, err
	}
	defer func() { _ = db.Close() }()
	return provider.HasPending(ctx)
}

type Store struct{ pool *pgxpool.Pool }

func Open(ctx context.Context, dsn string) (*Store, error) {
	config, err := pgxpool.ParseConfig(dsn)
	if err != nil {
		return nil, errors.New("invalid_database_config")
	}
	config.MaxConns = 5
	config.ConnConfig.RuntimeParams["statement_timeout"] = "10000"
	config.ConnConfig.RuntimeParams["lock_timeout"] = "2000"
	pool, err := pgxpool.NewWithConfig(ctx, config)
	if err != nil {
		return nil, err
	}
	if err = pool.Ping(ctx); err != nil {
		pool.Close()
		return nil, err
	}
	return &Store{pool: pool}, nil
}
func (s *Store) Close()                          { s.pool.Close() }
func (s *Store) Ready(ctx context.Context) error { return s.pool.Ping(ctx) }

// Apply is a full bounded configuration replacement. Lower versions and identity
// rebinding are rejected before commit; runtime and monetary history survive.
func (s *Store) Apply(ctx context.Context, policies []observation.Policy, now time.Time) error {
	if len(policies) > 10000 {
		return errors.New("capacity_exceeded")
	}
	seen := map[string]bool{}
	accounts := map[string]int{}
	for _, p := range policies {
		key := p.CompanyID + ":" + p.AccountID + ":" + p.ID
		if p.Validate() != nil || seen[key] {
			return errors.New("invalid_configuration")
		}
		seen[key] = true
		accounts[p.CompanyID+":"+p.AccountID]++
		if accounts[p.CompanyID+":"+p.AccountID] > 10 {
			return errors.New("account_capacity_exceeded")
		}
	}
	if policies == nil {
		policies = []observation.Policy{}
	}
	body, err := json.Marshal(policies)
	if err != nil {
		return err
	}
	tx, err := s.pool.Begin(ctx)
	if err != nil {
		return err
	}
	defer func() { _ = tx.Rollback(ctx) }() // rollback after commit is harmless
	if _, err = tx.Exec(ctx, "SELECT pg_advisory_xact_lock(52001)"); err != nil {
		return err
	}
	_, err = tx.Exec(ctx, `CREATE TEMP TABLE incoming ON COMMIT DROP AS SELECT x->>'company_id' AS company, x->>'account_id' AS account, x->>'policy_id' AS id,x->>'campaign_id' AS campaign,(x->>'version')::bigint AS version,x AS document FROM jsonb_array_elements($1::jsonb) x`, string(body))
	if err != nil {
		return err
	}
	var conflict bool
	err = tx.QueryRow(ctx, `SELECT EXISTS(SELECT 1 FROM incoming i JOIN ads_policy p ON p.company_id=i.company::uuid AND p.account_id=i.account::uuid AND p.policy_id=i.id::uuid WHERE p.version>i.version OR p.campaign_id<>i.campaign OR (p.version=i.version AND p.document-'permission_until'<>i.document-'permission_until'))`).Scan(&conflict)
	if err != nil {
		return err
	}
	if conflict {
		return errors.New("version_conflict")
	}
	_, err = tx.Exec(ctx, `INSERT INTO ads_account(company_id,account_id,next_poll,next_tick) SELECT DISTINCT company::uuid,account::uuid,$1::timestamptz,$1::timestamptz FROM incoming ON CONFLICT(company_id,account_id) DO UPDATE SET next_tick=LEAST(ads_account.next_tick,$1)`, now)
	if err != nil {
		return err
	}
	_, err = tx.Exec(ctx, `INSERT INTO ads_policy(company_id,account_id,policy_id,campaign_id,version,document) SELECT company::uuid,account::uuid,id::uuid,campaign,version,document FROM incoming ON CONFLICT(company_id,account_id,policy_id) DO UPDATE SET version=EXCLUDED.version,document=EXCLUDED.document`)
	if err != nil {
		return err
	}
	// Revocation keeps history, invalidates permission immediately, and wakes the timer.
	_, err = tx.Exec(ctx, `UPDATE ads_policy p SET document=jsonb_set(p.document,'{permission_until}',to_jsonb($1::text)) WHERE NOT EXISTS(SELECT 1 FROM incoming i WHERE i.company::uuid=p.company_id AND i.account::uuid=p.account_id AND i.id::uuid=p.policy_id)`, now.UTC().Format(time.RFC3339))
	if err != nil {
		return err
	}
	if err = s.reconcileSchedule(ctx, "", "", tx, now); err != nil {
		return err
	}
	return tx.Commit(ctx)
}

type Runtime struct {
	Day            string                `json:"day"`
	Primary        observation.Snapshot  `json:"primary"`
	SKU            *observation.Snapshot `json:"sku"`
	Effective      *observation.Money    `json:"effective"`
	Latched        bool                  `json:"latched"`
	Decision       observation.Decision  `json:"decision"`
	Error          string                `json:"error"`
	AppliedVersion int64                 `json:"applied_version"`
	EvaluatedAt    time.Time             `json:"evaluated_at"`
}
type Item struct {
	Policy  observation.Policy
	Runtime Runtime
}
type Claim struct {
	CompanyID, AccountID, Token string
	NextPoll                    time.Time
	Items                       []Item
}

// ClaimAccount is deliberately operational/cross-tenant. Every subsequent read
// and write requires its company/account and a fencing token.
func (s *Store) ClaimAccount(ctx context.Context, token string, now time.Time) (*Claim, error) {
	tx, err := s.pool.Begin(ctx)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback(ctx) }()
	c := &Claim{Token: token}
	err = tx.QueryRow(ctx, `WITH due AS (SELECT company_id,account_id,next_poll FROM ads_account WHERE next_tick<=$1 AND (lease_until IS NULL OR lease_until<=$1) ORDER BY next_tick,company_id,account_id FOR UPDATE SKIP LOCKED LIMIT 1) UPDATE ads_account a SET lease_token=$2,lease_until=$1+interval '90 seconds',last_poll_at=CASE WHEN a.next_poll<=$1 THEN $1 ELSE a.last_poll_at END,next_poll=CASE WHEN a.next_poll<=$1 THEN $1+make_interval(secs=>(SELECT COALESCE(MIN((document->>'poll_seconds')::int) FILTER(WHERE (document->>'enabled')::boolean AND (document->>'permission_until')::timestamptz>$1),86400) FROM ads_policy WHERE company_id=a.company_id AND account_id=a.account_id)) ELSE a.next_poll END FROM due WHERE a.company_id=due.company_id AND a.account_id=due.account_id RETURNING a.company_id::text,a.account_id::text,due.next_poll`, now, token).Scan(&c.CompanyID, &c.AccountID, &c.NextPoll)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	rows, err := tx.Query(ctx, `SELECT document,runtime FROM ads_policy WHERE company_id=$1 AND account_id=$2 ORDER BY policy_id LIMIT 11`, c.CompanyID, c.AccountID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	for rows.Next() {
		var document, runtime []byte
		if err = rows.Scan(&document, &runtime); err != nil {
			return nil, err
		}
		var item Item
		if json.Unmarshal(document, &item.Policy) != nil || json.Unmarshal(runtime, &item.Runtime) != nil {
			return nil, errors.New("invalid_persisted_state")
		}
		c.Items = append(c.Items, item)
	}
	if err = rows.Err(); err != nil {
		return nil, err
	}
	if len(c.Items) > 10 {
		return nil, errors.New("account_capacity_exceeded")
	}
	if err = tx.Commit(ctx); err != nil {
		return nil, err
	}
	return c, nil
}
func (s *Store) Finish(ctx context.Context, companyID, accountID, token string, items []Item, nextPoll, nextTick, now time.Time) error {
	paused := false
	updates := make([]struct {
		ID      string  `json:"id"`
		Version int64   `json:"version"`
		Runtime Runtime `json:"runtime"`
	}, 0, len(items))
	for _, item := range items {
		if item.Runtime.Error == "rate_limited" {
			paused = true
		}
		if item.Policy.CompanyID != companyID || item.Policy.AccountID != accountID {
			return errors.New("scope_mismatch")
		}
		updates = append(updates, struct {
			ID      string  `json:"id"`
			Version int64   `json:"version"`
			Runtime Runtime `json:"runtime"`
		}{item.Policy.ID, item.Policy.Version, item.Runtime})
	}
	body, err := json.Marshal(updates)
	if err != nil {
		return err
	}
	tx, err := s.pool.Begin(ctx)
	if err != nil {
		return err
	}
	defer func() { _ = tx.Rollback(ctx) }()
	tag, err := tx.Exec(ctx, `UPDATE ads_account SET pause_until=CASE WHEN $8 THEN GREATEST(pause_until,$4) ELSE pause_until END,next_tick=CASE WHEN EXISTS(SELECT 1 FROM ads_policy p WHERE p.company_id=ads_account.company_id AND p.account_id=ads_account.account_id AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements($7::jsonb) u WHERE p.policy_id=(u->>'id')::uuid AND p.version=(u->>'version')::bigint)) THEN $6 ELSE $5 END,lease_token=NULL,lease_until=NULL WHERE company_id=$1 AND account_id=$2 AND lease_token=$3 AND lease_until>$6`, companyID, accountID, token, nextPoll, nextTick, now, string(body), paused)
	if err != nil {
		return err
	}
	if tag.RowsAffected() != 1 {
		return errors.New("lease_lost")
	}
	_, err = tx.Exec(ctx, `UPDATE ads_policy p SET runtime=u->'runtime' FROM jsonb_array_elements($3::jsonb) u WHERE p.company_id=$1 AND p.account_id=$2 AND p.policy_id=(u->>'id')::uuid`, companyID, accountID, string(body))
	if err != nil {
		return err
	}
	if err = s.reconcileSchedule(ctx, companyID, accountID, tx, now); err != nil {
		return err
	}
	return tx.Commit(ctx)
}
func (s *Store) Status(ctx context.Context, companyID, accountID, policyID string) (Runtime, error) {
	var body []byte
	var result Runtime
	err := s.pool.QueryRow(ctx, `SELECT runtime FROM ads_policy WHERE company_id=$1 AND account_id=$2 AND policy_id=$3`, companyID, accountID, policyID).Scan(&body)
	if err != nil {
		return result, err
	}
	err = json.Unmarshal(body, &result)
	return result, err
}

// Take reserves one HTTP request in a rolling 24h window, conservatively across
// the whole service. Other consumers must be coordinated before live admission.
// A single global reservation never undercounts aliases of the same cabinet.
func (s *Store) Take(ctx context.Context, companyID, accountID string, now time.Time) error {
	if !observation.ValidUUID(companyID) || !observation.ValidUUID(accountID) {
		return errors.New("invalid_quota_scope")
	}
	tx, err := s.pool.Begin(ctx)
	if err != nil {
		return err
	}
	defer func() { _ = tx.Rollback(ctx) }()
	if _, err = tx.Exec(ctx, "SELECT pg_advisory_xact_lock(52002)"); err != nil {
		return err
	}
	var used int64
	if err = tx.QueryRow(ctx, `SELECT COALESCE(SUM(used),0)::bigint FROM ads_http_quota WHERE scope='service' AND window_start>$1::timestamptz-interval '24 hours'`, now.Add(-time.Minute)).Scan(&used); err != nil {
		return err
	}
	if used >= 70000 {
		return errors.New("http_quota_exhausted")
	}
	_, err = tx.Exec(ctx, `INSERT INTO ads_http_quota(scope,window_start,used) VALUES('service',date_trunc('minute',$1::timestamptz),1) ON CONFLICT(scope,window_start) DO UPDATE SET used=ads_http_quota.used+1`, now)
	if err != nil {
		return err
	}
	_, err = tx.Exec(ctx, `DELETE FROM ads_http_quota WHERE window_start<$1::timestamptz-interval '25 hours'`, now)
	if err != nil {
		return err
	}
	return tx.Commit(ctx)
}

type Publication struct {
	CompanyID, AccountID, PolicyID string
	Runtime                        Runtime
}

func (s *Store) Pending(ctx context.Context, limit int) ([]Publication, error) {
	if limit < 1 || limit > 100 {
		return nil, errors.New("invalid_limit")
	}
	rows, err := s.pool.Query(ctx, `SELECT company_id::text,account_id::text,policy_id::text,runtime FROM ads_policy WHERE runtime<>'{}'::jsonb AND (published_at IS NULL OR published_at<(runtime->>'evaluated_at')::timestamptz) ORDER BY (runtime->>'evaluated_at')::timestamptz,company_id,policy_id LIMIT $1`, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	result := []Publication{}
	for rows.Next() {
		var p Publication
		var body []byte
		if err = rows.Scan(&p.CompanyID, &p.AccountID, &p.PolicyID, &body); err != nil {
			return nil, err
		}
		if err = json.Unmarshal(body, &p.Runtime); err != nil {
			return nil, err
		}
		result = append(result, p)
	}
	return result, rows.Err()
}
func (s *Store) Published(ctx context.Context, companyID, accountID, policyID string, at time.Time) error {
	_, err := s.pool.Exec(ctx, `UPDATE ads_policy SET published_at=$4 WHERE company_id=$1 AND account_id=$2 AND policy_id=$3 AND (runtime->>'evaluated_at')::timestamptz=$4`, companyID, accountID, policyID, at)
	return err
}

// Only the non-secret connection revision is persisted. Late failures from old
// credentials cannot block a newer revision; restart does not clear a rejection.
func (s *Store) AuthorizationAllowed(ctx context.Context, companyID, accountID string, revision time.Time) error {
	var blocked bool
	err := s.pool.QueryRow(ctx, `SELECT EXISTS(SELECT 1 FROM ads_auth_rejection WHERE company_id=$1 AND account_id=$2 AND credential_revision>=$3)`, companyID, accountID, revision).Scan(&blocked)
	if err != nil {
		return err
	}
	if blocked {
		return errors.New("authorization_blocked")
	}
	return nil
}
func (s *Store) RejectAuthorization(ctx context.Context, companyID, accountID string, revision time.Time) error {
	_, err := s.pool.Exec(ctx, `INSERT INTO ads_auth_rejection(company_id,account_id,credential_revision)VALUES($1,$2,$3) ON CONFLICT(company_id,account_id) DO UPDATE SET credential_revision=GREATEST(ads_auth_rejection.credential_revision,EXCLUDED.credential_revision)`, companyID, accountID, revision)
	return err
}

// Empty scope is used only by the full operational configuration replacement.
// Account work always reconciles one explicit company/account, in its lease tx.
func (s *Store) reconcileSchedule(ctx context.Context, companyID, accountID string, tx pgx.Tx, now time.Time) error {
	scope := ""
	args := []any{now}
	if companyID != "" {
		scope = " WHERE a.company_id=$2 AND a.account_id=$3"
		args = append(args, companyID, accountID)
	}
	query := `WITH desired AS (
 SELECT a.company_id,a.account_id,GREATEST($1::timestamptz,
 CASE WHEN a.last_poll_at IS NULL THEN $1::timestamptz ELSE a.last_poll_at+make_interval(secs=>COALESCE(MIN((p.document->>'poll_seconds')::int) FILTER(WHERE (p.document->>'enabled')::boolean AND (p.document->>'permission_until')::timestamptz>$1),86400)) END,a.pause_until) AS due
 FROM ads_account a LEFT JOIN ads_policy p ON p.company_id=a.company_id AND p.account_id=a.account_id` + scope + `
 GROUP BY a.company_id,a.account_id,a.last_poll_at,a.pause_until)
 UPDATE ads_account a SET next_poll=d.due,next_tick=LEAST(a.next_tick,d.due) FROM desired d WHERE a.company_id=d.company_id AND a.account_id=d.account_id`
	_, err := tx.Exec(ctx, query, args...)
	return err
}
