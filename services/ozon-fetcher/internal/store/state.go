package store

import (
	"context"
	"fmt"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
)

// Limit — сохранённый лимит пары (кабинет, endpoint); 0 — не задано.
type Limit struct {
	Account  account.Ref `json:"-"`
	Endpoint string      `json:"endpoint"`
	Override float64     `json:"rps_override"`
	Current  float64     `json:"rps_current"`
}

// LoadLimitsAllConnections — все сохранённые лимиты (для ratelimit.Registry.Seed
// при старте). Межарендаторное чтение операционной задачи (CLAUDE.md §1,
// ADR-043): лимитер сервиса один на подключения всех компаний.
func (t *Tasks) LoadLimitsAllConnections(ctx context.Context) ([]Limit, error) {
	rows, err := t.pool.Query(ctx, `
		SELECT company_id, marketplace_account_id, endpoint, COALESCE(rps_override, 0)::float8, COALESCE(rps_current, 0)::float8
		FROM connection_limit`)
	if err != nil {
		return nil, fmt.Errorf("store: load limits: %w", err)
	}
	defer rows.Close()
	var limits []Limit
	for rows.Next() {
		var l Limit
		if err := rows.Scan(&l.Account.CompanyID, &l.Account.AccountID, &l.Endpoint, &l.Override, &l.Current); err != nil {
			return nil, fmt.Errorf("store: scan limit: %w", err)
		}
		limits = append(limits, l)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("store: load limits: %w", err)
	}
	return limits, nil
}

// SaveCurrentRate — адаптированная скорость пары (ratelimit OnChange).
func (t *Tasks) SaveCurrentRate(ctx context.Context, ref account.Ref, endpoint string, rps float64) error {
	if _, err := t.pool.Exec(ctx, `
		INSERT INTO connection_limit (company_id, marketplace_account_id, endpoint, rps_current, updated_at) VALUES ($1, $2, $3, $4, now())
		ON CONFLICT (company_id, marketplace_account_id, endpoint) DO UPDATE SET rps_current = EXCLUDED.rps_current, updated_at = now()`,
		ref.CompanyID, ref.AccountID, endpoint, rps); err != nil {
		return fmt.Errorf("store: save rate: %w", err)
	}
	return nil
}

// SetOverride — ручное значение лимита пары (0 — снять).
func (t *Tasks) SetOverride(ctx context.Context, ref account.Ref, endpoint string, rps float64) error {
	if _, err := t.pool.Exec(ctx, `
		INSERT INTO connection_limit (company_id, marketplace_account_id, endpoint, rps_override, updated_at) VALUES ($1, $2, $3, NULLIF($4, 0::float8), now())
		ON CONFLICT (company_id, marketplace_account_id, endpoint) DO UPDATE SET rps_override = EXCLUDED.rps_override, updated_at = now()`,
		ref.CompanyID, ref.AccountID, endpoint, rps); err != nil {
		return fmt.Errorf("store: set override: %w", err)
	}
	return nil
}

// SuspendConnection — пауза кабинета (auth): задачи не выдаются до until.
func (t *Tasks) SuspendConnection(ctx context.Context, ref account.Ref, reason string, until time.Time) error {
	if _, err := t.pool.Exec(ctx, `
		INSERT INTO connection_state (company_id, marketplace_account_id, suspended_until, suspend_reason, updated_at) VALUES ($1, $2, $3, $4, now())
		ON CONFLICT (company_id, marketplace_account_id) DO UPDATE SET suspended_until = EXCLUDED.suspended_until,
			suspend_reason = EXCLUDED.suspend_reason, updated_at = now()`,
		ref.CompanyID, ref.AccountID, until, reason); err != nil {
		return fmt.Errorf("store: suspend connection: %w", err)
	}
	return nil
}

// KeyFamily — ключ подключения: у Seller API и рекламы (Performance API)
// свои ключи, свои отказы и свои паузы.
type KeyFamily string

const (
	KeySeller KeyFamily = "seller"
	KeyAds    KeyFamily = "ads"
)

// authColumns — столбцы connection_state семейства ключа. Имена из этой
// таблицы, не из ввода.
func authColumns(family KeyFamily) (failures, version, suspended string) {
	if family == KeyAds {
		return "ads_auth_failures", "ads_auth_key_version", "ads_suspended_until"
	}
	return "auth_failures", "auth_key_version", "suspended_until"
}

// RecordAuthFailure — 401 по ключу семейства: счётчик отказов этой версии
// ключей +1 (другая версия — счёт заново с 1) и пауза на pauseFor(n);
// нулевая пауза — без паузы по времени: со второго отказа той же версии
// выдачу закрывает сама версия (ClaimNextAcrossConnections), пока
// в выдаче не появятся новые ключи (ADR-043 п. 4). Возвращает номер
// отказа этой версии.
func (t *Tasks) RecordAuthFailure(ctx context.Context, ref account.Ref, family KeyFamily, keyVersion, now time.Time, pauseFor func(failures int) time.Duration) (int, error) {
	failuresCol, versionCol, suspendedCol := authColumns(family)
	tx, err := t.pool.Begin(ctx)
	if err != nil {
		return 0, fmt.Errorf("store: auth failure begin: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	var failures int
	if err := tx.QueryRow(ctx, fmt.Sprintf(`
		INSERT INTO connection_state (company_id, marketplace_account_id, %[1]s, %[2]s, updated_at) VALUES ($1, $2, 1, $3, $4)
		ON CONFLICT (company_id, marketplace_account_id) DO UPDATE SET
			%[1]s = CASE WHEN connection_state.%[2]s = EXCLUDED.%[2]s THEN connection_state.%[1]s + 1 ELSE 1 END,
			%[2]s = EXCLUDED.%[2]s, updated_at = $4
		RETURNING %[1]s`, failuresCol, versionCol), ref.CompanyID, ref.AccountID, keyVersion, now).Scan(&failures); err != nil {
		return 0, fmt.Errorf("store: auth failure count: %w", err)
	}
	var until *time.Time
	if pause := pauseFor(failures); pause > 0 {
		u := now.Add(pause)
		until = &u
	}
	if _, err := tx.Exec(ctx, fmt.Sprintf(`
		UPDATE connection_state SET %s = $3 WHERE company_id = $1 AND marketplace_account_id = $2`, suspendedCol),
		ref.CompanyID, ref.AccountID, until); err != nil {
		return 0, fmt.Errorf("store: auth failure pause: %w", err)
	}
	if family == KeySeller {
		if _, err := tx.Exec(ctx, `
			UPDATE connection_state SET suspend_reason = CASE WHEN suspended_until IS NULL THEN NULL ELSE 'auth' END
			WHERE company_id = $1 AND marketplace_account_id = $2`, ref.CompanyID, ref.AccountID); err != nil {
			return 0, fmt.Errorf("store: auth failure reason: %w", err)
		}
	}
	if err := tx.Commit(ctx); err != nil {
		return 0, fmt.Errorf("store: auth failure commit: %w", err)
	}
	return failures, nil
}

// ResumeConnection снимает паузу и сбрасывает счётчик отказов ключа
// семейства (успешный запрос этим ключом).
func (t *Tasks) ResumeConnection(ctx context.Context, ref account.Ref, family KeyFamily) error {
	failuresCol, versionCol, suspendedCol := authColumns(family)
	reason := ""
	if family == KeySeller {
		reason = ", suspend_reason = NULL"
	}
	if _, err := t.pool.Exec(ctx, fmt.Sprintf(`
		UPDATE connection_state SET %[3]s = NULL, %[1]s = 0, %[2]s = NULL%[4]s, updated_at = now()
		WHERE company_id = $1 AND marketplace_account_id = $2 AND (%[3]s IS NOT NULL OR %[1]s > 0)`,
		failuresCol, versionCol, suspendedCol, reason), ref.CompanyID, ref.AccountID); err != nil {
		return fmt.Errorf("store: resume connection: %w", err)
	}
	return nil
}

// StopResourceAllConnections — ресурс снят у провайдера: задачи не выдаются ни
// одному кабинету. Межарендаторная запись операционной задачи (CLAUDE.md §1,
// ADR-043): resource_stop — без компании, остановка действует на всех.
func (t *Tasks) StopResourceAllConnections(ctx context.Context, resourceType, reason string) error {
	if _, err := t.pool.Exec(ctx, `
		INSERT INTO resource_stop (resource_type, reason) VALUES ($1, $2) ON CONFLICT (resource_type) DO NOTHING`,
		resourceType, reason); err != nil {
		return fmt.Errorf("store: stop resource: %w", err)
	}
	return nil
}
