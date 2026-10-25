package store

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
)

// Статусы fetch_task.
const (
	StatusPending         = "pending"
	StatusRunning         = "running"
	StatusDone            = "done"
	StatusNotReady        = "not_ready"
	StatusFailedRetryable = "failed_retryable"
	StatusFailedPermanent = "failed_permanent"
)

// ErrNoTask — готовой задачи нет (или её перехватил другой воркер).
var ErrNoTask = errors.New("store: no task ready")

// ErrLeaseLost — lease задачи истёк и перехвачен; результат попытки не записан.
var ErrLeaseLost = errors.New("store: lease lost")

// TaskKey — идентичность задачи; подключение — первым (services/AGENTS.md §4).
type TaskKey struct {
	Account      account.Ref
	ResourceType string
	PeriodKey    string
}

// TaskSpec — постановка задачи планировщиком.
type TaskSpec struct {
	TaskKey
	Priority int
	// Refresh — перевести done или failed_permanent задачу в pending.
	Refresh bool
	RunAt   time.Time
}

// Task — захваченная задача.
type Task struct {
	TaskKey
	AttemptID      string
	Attempts       int
	Cursor         string
	NextPageNo     int
	PageHashes     []string // sha256 страниц текущей загрузки, включая прежние попытки
	ContentHash    string
	Revision       int
	LastErrorClass string
	LeaseUntil     time.Time
}

// Tasks — очередь fetch_task.
type Tasks struct {
	pool *Pool
}

func NewTasks(pool *Pool) *Tasks { return &Tasks{pool: pool} }

// UpsertAcrossConnections ставит задачу идемпотентно: running не трогает, приоритет —
// максимум, done и failed_permanent переводит в pending только при Refresh
// (плановая перезагрузка повторяет и задачи, упавшие на ответе Ozon). Refresh
// задачи с незавершённой загрузкой откладывается до её итога (refresh_pending).
//
// Межарендаторная запись операционной задачи (CLAUDE.md §1, ADR-043):
// планировщик ставит задачи подключений всех компаний одним вызовом; каждая
// задача несёт своё подключение в TaskKey.
func (t *Tasks) UpsertAcrossConnections(ctx context.Context, specs []TaskSpec) error {
	if len(specs) == 0 {
		return nil
	}
	return sendUpserts(ctx, t.pool, specs)
}

// batchSender — пул или транзакция.
type batchSender interface {
	SendBatch(ctx context.Context, b *pgx.Batch) pgx.BatchResults
}

func sendUpserts(ctx context.Context, conn batchSender, specs []TaskSpec) error {
	batch := &pgx.Batch{}
	for _, s := range specs {
		batch.Queue(`
			INSERT INTO fetch_task (company_id, marketplace_account_id, resource_type, period_key, priority, next_run_at)
			VALUES ($1, $2, $3, $4, $5, $6)
			ON CONFLICT (company_id, marketplace_account_id, resource_type, period_key) DO UPDATE SET
				priority    = GREATEST(fetch_task.priority, EXCLUDED.priority),
				status      = CASE WHEN fetch_task.status IN ('done', 'failed_permanent') AND $7 THEN 'pending' ELSE fetch_task.status END,
				-- Перезагрузка запущенной задачи или незапущенной с прогрессом
				-- (курсор после 429/рестарта, страницы без записанного итога) —
				-- отложенная: попытка дочитывает окно, затем окно заново. Сброс сразу
				-- терял бы прогресс на каждом часовом проходе, продолжение без флага
				-- поглощало бы однократный проход (хвост, сверку).
				refresh_pending = fetch_task.refresh_pending OR ($7 AND (fetch_task.status = 'running'
					OR (fetch_task.status IN ('pending', 'not_ready', 'failed_retryable')
						AND (fetch_task.cursor IS NOT NULL OR fetch_task.page_hashes <> '')))),
				next_run_at = CASE
					WHEN fetch_task.status IN ('done', 'failed_permanent') AND $7 THEN EXCLUDED.next_run_at
					WHEN fetch_task.status IN ('pending', 'not_ready', 'failed_retryable') AND EXCLUDED.priority > fetch_task.priority
						THEN LEAST(fetch_task.next_run_at, EXCLUDED.next_run_at)
					ELSE fetch_task.next_run_at END,
				updated_at  = now()`,
			s.Account.CompanyID, s.Account.AccountID, s.ResourceType, s.PeriodKey, s.Priority, s.RunAt, s.Refresh)
	}
	if err := conn.SendBatch(ctx, batch).Close(); err != nil {
		return fmt.Errorf("store: upsert tasks: %w", err)
	}
	return nil
}

// ErrForeignSpec — в PlanOnce передана задача другого подключения.
var ErrForeignSpec = errors.New("store: spec of another connection")

// PlanOnce ставит specs, только если проход kind за runDate для подключения
// ещё не выполнялся: маркер planner_run и постановка — одна транзакция.
// false — уже выполнен. Задача другого подключения — ErrForeignSpec: маркер
// одного подключения не ставит задачи другого.
func (t *Tasks) PlanOnce(ctx context.Context, ref account.Ref, kind, runDate string, specs []TaskSpec) (bool, error) {
	for _, spec := range specs {
		if spec.Account != ref {
			return false, ErrForeignSpec
		}
	}
	tx, err := t.pool.Begin(ctx)
	if err != nil {
		return false, fmt.Errorf("store: plan once begin: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	tag, err := tx.Exec(ctx, `INSERT INTO planner_run (company_id, marketplace_account_id, kind, run_date) VALUES ($1, $2, $3, $4) ON CONFLICT DO NOTHING`,
		ref.CompanyID, ref.AccountID, kind, runDate)
	if err != nil {
		return false, fmt.Errorf("store: plan once marker: %w", err)
	}
	if tag.RowsAffected() == 0 {
		return false, nil
	}
	if len(specs) > 0 {
		if err := sendUpserts(ctx, tx, specs); err != nil {
			return false, err
		}
	}
	if err := tx.Commit(ctx); err != nil {
		return false, fmt.Errorf("store: plan once commit: %w", err)
	}
	return true, nil
}

// Connection — подключение снимка выдачи и версия его ключей (updated_at
// выдачи): по ней выдача задач узнаёт, что отказанный ключ заменён.
type Connection struct {
	Account    account.Ref
	KeyVersion time.Time
}

// ClaimNextAcrossConnections захватывает готовую задачу одного из conns (активный снимок):
// подключение без running-задачи и без паузы, ресурс не остановлен, ключ
// семейства задачи не отказан дважды в выданной версии.
// Порядок — приоритет, давно не обслуживавшееся подключение, время.
// Межарендаторное чтение операционной задачи (CLAUDE.md §1, ADR-043):
// диспетчер обслуживает подключения всех компаний.
func (t *Tasks) ClaimNextAcrossConnections(ctx context.Context, conns []Connection, owner, attemptID, traceID string, now time.Time, lease time.Duration) (Task, error) {
	if len(conns) == 0 {
		return Task{}, ErrNoTask
	}
	refs := make([]account.Ref, len(conns))
	versions := make([]time.Time, len(conns))
	for i, c := range conns {
		refs[i], versions[i] = c.Account, c.KeyVersion
	}
	companies, accounts := splitRefs(refs)
	tx, err := t.pool.Begin(ctx)
	if err != nil {
		return Task{}, fmt.Errorf("store: claim begin: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	var task Task
	var pageHashes string
	err = tx.QueryRow(ctx, `
		SELECT t.company_id, t.marketplace_account_id, t.resource_type, t.period_key, t.attempts,
		       COALESCE(t.cursor, ''), t.next_page_no, t.page_hashes, COALESCE(t.content_hash, ''), t.revision,
		       COALESCE(t.last_error_class, '')
		FROM fetch_task t
		JOIN unnest($1::uuid[], $2::uuid[], $4::timestamptz[]) AS v(company_id, marketplace_account_id, key_version)
		       ON v.company_id = t.company_id AND v.marketplace_account_id = t.marketplace_account_id
		LEFT JOIN connection_state cs
		       ON cs.company_id = t.company_id AND cs.marketplace_account_id = t.marketplace_account_id
		WHERE t.status IN ('pending', 'not_ready', 'failed_retryable')
		  AND t.next_run_at <= $3
		  -- Отказ ключа (ADR-043 п. 4) — по семейству задачи: у рекламы свой
		  -- ключ. Короткая пауза после первого 401; со второго отказа той же
		  -- версии — до новых ключей в выдаче, без срока.
		  AND CASE WHEN t.resource_type LIKE 'ozon.ads.%'
		      THEN (cs.ads_suspended_until IS NULL OR cs.ads_suspended_until <= $3)
		           AND NOT (COALESCE(cs.ads_auth_failures, 0) >= $5 AND cs.ads_auth_key_version IS NOT DISTINCT FROM v.key_version)
		      ELSE (cs.suspended_until IS NULL OR cs.suspended_until <= $3)
		           AND NOT (COALESCE(cs.auth_failures, 0) >= $5 AND cs.auth_key_version IS NOT DISTINCT FROM v.key_version)
		      END
		  -- Одна running-задача на (подключение, семейство API): индекс
		  -- fetch_task_one_running_per_connection_family.
		  AND NOT EXISTS (SELECT 1 FROM fetch_task r
		                  WHERE r.company_id = t.company_id AND r.marketplace_account_id = t.marketplace_account_id
		                  AND r.status = 'running'
		                  AND (r.resource_type LIKE 'ozon.ads.%') = (t.resource_type LIKE 'ozon.ads.%'))
		  AND NOT EXISTS (SELECT 1 FROM resource_stop s WHERE s.resource_type = t.resource_type)
		  -- Признак ограничения доступа Ozon (ADR-043 п. 5): остановка всех
		  -- подключений и пауза ресурса подключения.
		  AND NOT EXISTS (SELECT 1 FROM service_halt)
		  AND NOT EXISTS (SELECT 1 FROM resource_pause p
		                  WHERE p.company_id = t.company_id AND p.marketplace_account_id = t.marketplace_account_id
		                  AND p.resource_type = t.resource_type AND p.paused_until > $3)
		ORDER BY t.priority DESC, cs.last_served_at ASC NULLS FIRST, t.next_run_at ASC
		LIMIT 1
		FOR UPDATE OF t SKIP LOCKED`,
		companies, accounts, now, versions, AuthPauseFailures,
	).Scan(&task.Account.CompanyID, &task.Account.AccountID, &task.ResourceType, &task.PeriodKey, &task.Attempts,
		&task.Cursor, &task.NextPageNo, &pageHashes, &task.ContentHash, &task.Revision, &task.LastErrorClass)
	if errors.Is(err, pgx.ErrNoRows) {
		return Task{}, ErrNoTask
	}
	if err != nil {
		return Task{}, fmt.Errorf("store: claim select: %w", err)
	}

	if pageHashes != "" {
		task.PageHashes = strings.Split(pageHashes, ",")
	}
	task.AttemptID = attemptID
	task.Attempts++
	task.LeaseUntil = now.Add(lease)
	if _, err := tx.Exec(ctx, `
		UPDATE fetch_task SET status = 'running', attempts = attempts + 1, lease_owner = $5,
			lease_until = $6, attempt_id = $7, updated_at = $8
		WHERE company_id = $1 AND marketplace_account_id = $2 AND resource_type = $3 AND period_key = $4`,
		task.Account.CompanyID, task.Account.AccountID, task.ResourceType, task.PeriodKey, owner, task.LeaseUntil, attemptID, now,
	); err != nil {
		if isUniqueViolation(err) {
			// Другой воркер успел захватить задачу этого кабинета и семейства API.
			return Task{}, ErrNoTask
		}
		return Task{}, fmt.Errorf("store: claim update: %w", err)
	}
	if _, err := tx.Exec(ctx, `
		INSERT INTO fetch_attempt (attempt_id, company_id, marketplace_account_id, resource_type, period_key, trace_id, lease_owner, started_at)
		VALUES ($1, $2, $3, $4, $5, $6, $7, $8)`,
		attemptID, task.Account.CompanyID, task.Account.AccountID, task.ResourceType, task.PeriodKey, traceID, owner, now,
	); err != nil {
		return Task{}, fmt.Errorf("store: claim attempt: %w", err)
	}
	if _, err := tx.Exec(ctx, `
		INSERT INTO connection_state (company_id, marketplace_account_id, last_served_at, updated_at) VALUES ($1, $2, $3, $3)
		ON CONFLICT (company_id, marketplace_account_id) DO UPDATE SET last_served_at = EXCLUDED.last_served_at, updated_at = EXCLUDED.updated_at`,
		task.Account.CompanyID, task.Account.AccountID, now,
	); err != nil {
		return Task{}, fmt.Errorf("store: claim served: %w", err)
	}
	if err := tx.Commit(ctx); err != nil {
		if isUniqueViolation(err) {
			return Task{}, ErrNoTask
		}
		return Task{}, fmt.Errorf("store: claim commit: %w", err)
	}
	return task, nil
}

// ExtendLease продлевает lease своей попытки.
func (t *Tasks) ExtendLease(ctx context.Context, key TaskKey, attemptID string, until time.Time) error {
	tag, err := t.pool.Exec(ctx, `
		UPDATE fetch_task SET lease_until = $6, updated_at = now()
		WHERE company_id = $1 AND marketplace_account_id = $2 AND resource_type = $3 AND period_key = $4 AND status = 'running' AND attempt_id = $5`,
		key.Account.CompanyID, key.Account.AccountID, key.ResourceType, key.PeriodKey, attemptID, until)
	if err != nil {
		return fmt.Errorf("store: extend lease: %w", err)
	}
	if tag.RowsAffected() == 0 {
		return ErrLeaseLost
	}
	return nil
}

// RawObject — сохранённая в S3 страница.
type RawObject struct {
	PageNo      int
	StorageURI  string
	SHA256      string
	SizeBytes   int64
	StoredBytes int64
	ContentType string
}

// FindObject — уже сохранённый объект этой задачи с тем же содержимым
// (sha256 несжатых байтов): повторная загрузка без изменений не пишет в S3.
func (t *Tasks) FindObject(ctx context.Context, key TaskKey, sha256 string) (RawObject, bool, error) {
	var obj RawObject
	err := t.pool.QueryRow(ctx, `
		SELECT r.page_no, r.storage_uri, r.sha256, r.size_bytes, r.stored_bytes, r.content_type
		FROM raw_object r JOIN fetch_attempt a
			ON a.company_id = r.company_id AND a.marketplace_account_id = r.marketplace_account_id AND a.attempt_id = r.attempt_id
		WHERE r.company_id = $1 AND r.marketplace_account_id = $2 AND a.resource_type = $3 AND a.period_key = $4 AND r.sha256 = $5
		ORDER BY r.created_at DESC LIMIT 1`,
		key.Account.CompanyID, key.Account.AccountID, key.ResourceType, key.PeriodKey, sha256,
	).Scan(&obj.PageNo, &obj.StorageURI, &obj.SHA256, &obj.SizeBytes, &obj.StoredBytes, &obj.ContentType)
	if errors.Is(err, pgx.ErrNoRows) {
		return RawObject{}, false, nil
	}
	if err != nil {
		return RawObject{}, false, fmt.Errorf("store: find object: %w", err)
	}
	return obj, true, nil
}

// ErrNoContent — у задачи нет загруженной ревизии (ещё не было done).
var ErrNoContent = errors.New("store: task has no loaded content")

// ErrMissingObject — у страницы ревизии нет объекта в raw_object (нарушен инвариант).
var ErrMissingObject = errors.New("store: content page has no raw object")

// Content — текущая загруженная ревизия задачи.
type Content struct {
	// Key — задача ревизии: из неё и полей страницы пересобирается ключ
	// объекта при чтении (CLAUDE.md §1, ADR-044).
	Key   TaskKey
	Pages []RawObject // по порядку, PageNo с 1
	// StartedAt — запись первой страницы ревизии: вся ревизия загружена не
	// раньше этого момента (страницы прежних загрузок в неё не входят).
	StartedAt time.Time
}

// ContentPages — объекты страниц текущей загруженной ревизии задачи
// (content_pages последнего done). Нет ревизии — ErrNoContent; страница без
// объекта — ErrMissingObject, не укороченный список.
func (t *Tasks) ContentPages(ctx context.Context, key TaskKey) (Content, error) {
	var pages string
	var started *time.Time
	err := t.pool.QueryRow(ctx, `
		SELECT content_pages, content_started_at FROM fetch_task WHERE company_id = $1 AND marketplace_account_id = $2 AND resource_type = $3 AND period_key = $4`,
		key.Account.CompanyID, key.Account.AccountID, key.ResourceType, key.PeriodKey).Scan(&pages, &started)
	if errors.Is(err, pgx.ErrNoRows) || (err == nil && (pages == "" || started == nil)) {
		return Content{}, ErrNoContent
	}
	if err != nil {
		return Content{}, fmt.Errorf("store: content pages: %w", err)
	}
	hashes := strings.Split(pages, ",")
	rows, err := t.pool.Query(ctx, `
		SELECT DISTINCT ON (r.sha256) r.sha256, r.storage_uri, r.size_bytes, r.stored_bytes, r.content_type
		FROM raw_object r JOIN fetch_attempt a
			ON a.company_id = r.company_id AND a.marketplace_account_id = r.marketplace_account_id AND a.attempt_id = r.attempt_id
		WHERE r.company_id = $1 AND r.marketplace_account_id = $2 AND a.resource_type = $3 AND a.period_key = $4 AND r.sha256 = ANY($5)
		ORDER BY r.sha256, r.created_at DESC`,
		key.Account.CompanyID, key.Account.AccountID, key.ResourceType, key.PeriodKey, hashes)
	if err != nil {
		return Content{}, fmt.Errorf("store: content objects: %w", err)
	}
	defer rows.Close()
	bySHA := map[string]RawObject{}
	for rows.Next() {
		var obj RawObject
		if err := rows.Scan(&obj.SHA256, &obj.StorageURI, &obj.SizeBytes, &obj.StoredBytes, &obj.ContentType); err != nil {
			return Content{}, fmt.Errorf("store: content objects scan: %w", err)
		}
		bySHA[obj.SHA256] = obj
	}
	if err := rows.Err(); err != nil {
		return Content{}, fmt.Errorf("store: content objects: %w", err)
	}
	out := Content{Key: key, Pages: make([]RawObject, 0, len(hashes)), StartedAt: *started}
	for i, h := range hashes {
		obj, ok := bySHA[h]
		if !ok {
			return Content{}, fmt.Errorf("%w: page %d of %s/%s/%s", ErrMissingObject, i+1, key.Account, key.ResourceType, key.PeriodKey)
		}
		obj.PageNo = i + 1
		out.Pages = append(out.Pages, obj)
	}
	return out, nil
}

// ReloadWithoutContent ставит на полную перезагрузку done-задачу, у которой
// нет списка страниц ревизии или времени её начала (загрузка начата до
// миграции 00004): зависимому ресурсу без них не из чего читать. У done-задачи
// прогресс уже сброшен — загрузка пойдёт с первой страницы. true — поставлена.
func (t *Tasks) ReloadWithoutContent(ctx context.Context, key TaskKey, now time.Time) (bool, error) {
	tag, err := t.pool.Exec(ctx, `
		UPDATE fetch_task SET status = 'pending', next_run_at = $5, updated_at = $5
		WHERE company_id = $1 AND marketplace_account_id = $2 AND resource_type = $3 AND period_key = $4
		  AND status = 'done' AND (content_pages = '' OR content_started_at IS NULL)`,
		key.Account.CompanyID, key.Account.AccountID, key.ResourceType, key.PeriodKey, now)
	if err != nil {
		return false, fmt.Errorf("store: reload without content: %w", err)
	}
	return tag.RowsAffected() > 0, nil
}

// RecordPage — страница и курсор следующей одной транзакцией.
func (t *Tasks) RecordPage(ctx context.Context, key TaskKey, attemptID string, obj RawObject, nextCursor string) error {
	tx, err := t.pool.Begin(ctx)
	if err != nil {
		return fmt.Errorf("store: record page begin: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	tag, err := tx.Exec(ctx, `
		UPDATE fetch_task SET cursor = NULLIF($6, ''), next_page_no = $7,
			page_hashes = CASE WHEN page_hashes = '' THEN $8 ELSE page_hashes || ',' || $8 END,
			-- Начало загрузки — начало попытки с первой страницей (до первого
			-- запроса), не время записи: ответ мог прийти уже после полуночи.
			load_started_at = CASE WHEN page_hashes = '' THEN (SELECT a.started_at FROM fetch_attempt a WHERE a.company_id = $1 AND a.marketplace_account_id = $2 AND a.attempt_id = $5) ELSE load_started_at END,
			updated_at = now()
		WHERE company_id = $1 AND marketplace_account_id = $2 AND resource_type = $3 AND period_key = $4 AND status = 'running' AND attempt_id = $5`,
		key.Account.CompanyID, key.Account.AccountID, key.ResourceType, key.PeriodKey, attemptID, nextCursor, obj.PageNo+1, obj.SHA256)
	if err != nil {
		return fmt.Errorf("store: record page cursor: %w", err)
	}
	if tag.RowsAffected() == 0 {
		return ErrLeaseLost
	}
	if _, err := tx.Exec(ctx, `
		INSERT INTO raw_object (company_id, marketplace_account_id, attempt_id, page_no, storage_uri, sha256, size_bytes, stored_bytes, content_type)
		VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)`,
		key.Account.CompanyID, key.Account.AccountID, attemptID, obj.PageNo, obj.StorageURI, obj.SHA256, obj.SizeBytes, obj.StoredBytes, obj.ContentType); err != nil {
		return fmt.Errorf("store: record page object: %w", err)
	}
	if _, err := tx.Exec(ctx, `
		UPDATE fetch_attempt SET pages = pages + 1, bytes = bytes + $4
		WHERE company_id = $1 AND marketplace_account_id = $2 AND attempt_id = $3`,
		key.Account.CompanyID, key.Account.AccountID, attemptID, obj.SizeBytes); err != nil {
		return fmt.Errorf("store: record page attempt: %w", err)
	}
	if err := tx.Commit(ctx); err != nil {
		return fmt.Errorf("store: record page commit: %w", err)
	}
	return nil
}

// Outcome — итог попытки для FinishTask.
type Outcome struct {
	Status      string // done | not_ready | failed_retryable | failed_permanent | pending (released)
	Label       string // outcome в fetch_attempt: done | unchanged | <класс ошибки> | released
	ErrorClass  string
	HTTPStatus  int
	NextRunAt   time.Time // для не-done
	ContentHash string    // для done
	// KeepCursor — повтор продолжит с сохранённого курсора (released, transient).
	KeepCursor bool
}

// FinishTask закрывает попытку. Новый content_hash — revision+1; тот же — без изменений.
// Возвращает итоговую revision.
func (t *Tasks) FinishTask(ctx context.Context, key TaskKey, attemptID string, out Outcome, now time.Time) (int, error) {
	tx, err := t.pool.Begin(ctx)
	if err != nil {
		return 0, fmt.Errorf("store: finish begin: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	// refresh_pending (перезагрузка пришла во время незавершённой загрузки):
	// итог попытки записывается; на окончательном итоге задача снова pending с
	// первой страницы, на промежуточном (повтор с курсором) — флаг ждёт.
	var revision int
	err = tx.QueryRow(ctx, `
		UPDATE fetch_task SET
			status       = CASE WHEN refresh_pending AND $6 IN ('done', 'failed_permanent') THEN 'pending' ELSE $6 END,
			lease_owner  = NULL, lease_until = NULL, attempt_id = NULL,
			next_run_at  = CASE WHEN refresh_pending AND $6 IN ('done', 'failed_permanent') THEN $11 WHEN $6 = 'done' THEN next_run_at ELSE $7 END,
			cursor       = CASE WHEN $8 THEN cursor ELSE NULL END,
			next_page_no = CASE WHEN $8 THEN next_page_no ELSE 1 END,
			page_hashes  = CASE WHEN $8 THEN page_hashes ELSE '' END,
			refresh_pending = refresh_pending AND $6 NOT IN ('done', 'failed_permanent'),
			revision     = CASE WHEN $6 = 'done' AND content_hash IS DISTINCT FROM NULLIF($9, '') THEN revision + 1 ELSE revision END,
			content_hash = CASE WHEN $6 = 'done' THEN NULLIF($9, '') ELSE content_hash END,
			content_pages = CASE WHEN $6 = 'done' THEN page_hashes ELSE content_pages END,
			content_started_at = CASE WHEN $6 = 'done' THEN load_started_at ELSE content_started_at END,
			attempts     = CASE WHEN $6 = 'done' THEN 0 ELSE attempts END,
			last_error_class = NULLIF($10, ''),
			last_success_at  = CASE WHEN $6 = 'done' THEN $11 ELSE last_success_at END,
			updated_at   = $11
		WHERE company_id = $1 AND marketplace_account_id = $2 AND resource_type = $3 AND period_key = $4 AND status = 'running' AND attempt_id = $5
		RETURNING revision`,
		key.Account.CompanyID, key.Account.AccountID, key.ResourceType, key.PeriodKey, attemptID,
		out.Status, out.NextRunAt, out.KeepCursor, out.ContentHash, out.ErrorClass, now,
	).Scan(&revision)
	if errors.Is(err, pgx.ErrNoRows) {
		return 0, ErrLeaseLost
	}
	if err != nil {
		return 0, fmt.Errorf("store: finish task: %w", err)
	}
	if _, err := tx.Exec(ctx, `
		UPDATE fetch_attempt SET finished_at = $4, outcome = $5, http_status = NULLIF($6, 0)
		WHERE company_id = $1 AND marketplace_account_id = $2 AND attempt_id = $3`,
		key.Account.CompanyID, key.Account.AccountID, attemptID, now, out.Label, out.HTTPStatus); err != nil {
		return 0, fmt.Errorf("store: finish attempt: %w", err)
	}
	if err := countOutcome(ctx, tx, key.Account, out.Label, now); err != nil {
		return 0, err
	}
	if err := tx.Commit(ctx); err != nil {
		return 0, fmt.Errorf("store: finish commit: %w", err)
	}
	return revision, nil
}

// ReleaseExpiredAcrossConnections возвращает в pending задачи с истёкшим lease; курсор
// сохраняется — следующий воркер продолжит с той же страницы.
//
// Один запрос: строки задач блокируются первыми (тот же порядок, что у
// RecordPage/FinishTask: задача → попытка), занятые воркером пропускаются
// (SKIP LOCKED), и попытки закрываются ровно для освобождённого набора —
// продление lease между выборками не может рассинхронизировать задачу и попытку.
//
// Межарендаторная запись операционной задачи (CLAUDE.md §1, ADR-043):
// сборщик просроченных lease обслуживает подключения всех компаний.
func (t *Tasks) ReleaseExpiredAcrossConnections(ctx context.Context, now time.Time) (int64, error) {
	var released int64
	err := t.pool.QueryRow(ctx, `
		WITH expired AS (
			SELECT company_id, marketplace_account_id, resource_type, period_key, attempt_id
			FROM fetch_task
			WHERE status = 'running' AND lease_until < $1
			FOR UPDATE SKIP LOCKED
		), released AS (
			-- Курсор сохраняется, кроме ожидающей перезагрузки: она — с первой страницы.
			UPDATE fetch_task t SET status = 'pending', lease_owner = NULL, lease_until = NULL, attempt_id = NULL,
				next_run_at = $1, last_error_class = 'lease_expired', updated_at = $1,
				cursor = CASE WHEN t.refresh_pending THEN NULL ELSE t.cursor END,
				next_page_no = CASE WHEN t.refresh_pending THEN 1 ELSE t.next_page_no END,
				page_hashes = CASE WHEN t.refresh_pending THEN '' ELSE t.page_hashes END,
				refresh_pending = false
			FROM expired e
			WHERE t.company_id = e.company_id AND t.marketplace_account_id = e.marketplace_account_id
			  AND t.resource_type = e.resource_type AND t.period_key = e.period_key
			RETURNING e.company_id, e.marketplace_account_id, e.attempt_id
		), closed AS (
			UPDATE fetch_attempt a SET finished_at = $1, outcome = 'lease_expired'
			FROM released r
			WHERE a.company_id = r.company_id AND a.marketplace_account_id = r.marketplace_account_id AND a.attempt_id = r.attempt_id
			RETURNING 1
		), counted AS (
			-- Счётчик исходов за час (attempt_outcome_hour, тот же, что у FinishTask).
			INSERT INTO attempt_outcome_hour (company_id, marketplace_account_id, hour, outcome, attempts)
			SELECT company_id, marketplace_account_id, date_trunc('hour', $1::timestamptz), 'lease_expired', count(*)
			FROM released GROUP BY 1, 2
			ON CONFLICT (company_id, marketplace_account_id, hour, outcome)
			DO UPDATE SET attempts = attempt_outcome_hour.attempts + EXCLUDED.attempts
			RETURNING 1
		)
		SELECT count(*) FROM released`, now).Scan(&released)
	if err != nil {
		return 0, fmt.Errorf("store: release expired: %w", err)
	}
	return released, nil
}

// OutcomeRetention — сколько хранится счётчик исходов за час.
const OutcomeRetention = 48 * time.Hour

// countOutcome — +1 к счётчику исхода подключения за текущий час в
// транзакции закрытия попытки. Первая запись нового часа подключения
// удаляет его часы старше OutcomeRetention: таблица не растёт.
func countOutcome(ctx context.Context, tx pgx.Tx, ref account.Ref, outcome string, now time.Time) error {
	var opened bool
	if err := tx.QueryRow(ctx, `
		INSERT INTO attempt_outcome_hour (company_id, marketplace_account_id, hour, outcome, attempts)
		VALUES ($1, $2, date_trunc('hour', $3::timestamptz), $4, 1)
		ON CONFLICT (company_id, marketplace_account_id, hour, outcome)
		DO UPDATE SET attempts = attempt_outcome_hour.attempts + 1
		RETURNING xmax = 0`, ref.CompanyID, ref.AccountID, now, outcome).Scan(&opened); err != nil {
		return fmt.Errorf("store: count outcome: %w", err)
	}
	if !opened {
		return nil
	}
	if _, err := tx.Exec(ctx, `
		DELETE FROM attempt_outcome_hour WHERE company_id = $1 AND marketplace_account_id = $2 AND hour < $3`,
		ref.CompanyID, ref.AccountID, now.Add(-OutcomeRetention)); err != nil {
		return fmt.Errorf("store: prune outcomes: %w", err)
	}
	return nil
}

// splitRefs — параллельные массивы компаний и подключений для unnest.
func splitRefs(refs []account.Ref) (companies, accounts []string) {
	companies = make([]string, 0, len(refs))
	accounts = make([]string, 0, len(refs))
	for _, r := range refs {
		companies = append(companies, r.CompanyID)
		accounts = append(accounts, r.AccountID)
	}
	return companies, accounts
}

func isUniqueViolation(err error) bool {
	var pgErr *pgconn.PgError
	return errors.As(err, &pgErr) && pgErr.Code == "23505"
}

// SaveCursor записывает курсор попытки без страницы (номер заказанного
// отчёта рекламы): рестарт продолжает опрос того же отчёта.
func (t *Tasks) SaveCursor(ctx context.Context, key TaskKey, attemptID, cursor string) error {
	tag, err := t.pool.Exec(ctx, `
		UPDATE fetch_task SET cursor = NULLIF($6, ''), updated_at = now()
		WHERE company_id = $1 AND marketplace_account_id = $2 AND resource_type = $3 AND period_key = $4 AND status = 'running' AND attempt_id = $5`,
		key.Account.CompanyID, key.Account.AccountID, key.ResourceType, key.PeriodKey, attemptID, cursor)
	if err != nil {
		return fmt.Errorf("store: save cursor: %w", err)
	}
	if tag.RowsAffected() == 0 {
		return ErrLeaseLost
	}
	return nil
}

// AdsReportInFlight — у кабинета есть другая незавершённая задача отчётов
// рекламы с заказанным отчётом (третье поле курсора «отпечаток:порция:UUID:…»):
// у аккаунта Performance — не больше одного отчёта одновременно.
func (t *Tasks) AdsReportInFlight(ctx context.Context, except TaskKey) (bool, error) {
	var found bool
	err := t.pool.QueryRow(ctx, `
		SELECT EXISTS (SELECT 1 FROM fetch_task
			WHERE company_id = $1 AND marketplace_account_id = $2 AND resource_type LIKE 'ozon.ads.statistics%'
			  AND NOT (resource_type = $3 AND period_key = $4)
			  AND status <> 'done' AND split_part(COALESCE(cursor, ''), ':', 3) <> '')`,
		except.Account.CompanyID, except.Account.AccountID, except.ResourceType, except.PeriodKey).Scan(&found)
	if err != nil {
		return false, fmt.Errorf("store: ads report in flight: %w", err)
	}
	return found, nil
}
