package store

import (
	"context"
	"fmt"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
)

// Признак ограничения доступа Ozon (ADR-043 п. 5): 403 на методе, который
// этим же ключом уже отвечал 200, — остановка всей загрузки до ручного
// снятия; 403 без прежнего 200 — пауза только этого ресурса подключения.

// MarkAttemptKey записывает версию ключей подключения (updated_at выдачи),
// с которой идёт попытка. Время — с точностью PostgreSQL, чтобы сравнение
// в ServedWithKey было точным.
func (t *Tasks) MarkAttemptKey(ctx context.Context, ref account.Ref, attemptID string, keyVersion time.Time) error {
	if _, err := t.pool.Exec(ctx, `
		UPDATE fetch_attempt SET key_version = $4
		WHERE company_id = $1 AND marketplace_account_id = $2 AND attempt_id = $3`,
		ref.CompanyID, ref.AccountID, attemptID, keyVersion.Truncate(time.Microsecond)); err != nil {
		return fmt.Errorf("store: mark attempt key: %w", err)
	}
	return nil
}

// ServedWithKey — ресурс подключения уже отвечал 200 ключом этой версии:
// есть попытка с той же версией ключа, которая завершилась успехом или
// записала хотя бы одну страницу (прерванная или текущая многостраничная
// загрузка — тоже 200). Метод Ozon у ресурса один, поэтому «метод уже
// отвечал 200 этим ключом» читается по журналу попыток ресурса.
func (t *Tasks) ServedWithKey(ctx context.Context, ref account.Ref, resourceType string, keyVersion time.Time) (bool, error) {
	var ok bool
	if err := t.pool.QueryRow(ctx, `
		SELECT EXISTS (
			SELECT 1 FROM fetch_attempt
			WHERE company_id = $1 AND marketplace_account_id = $2 AND resource_type = $3
			  AND key_version = $4 AND (outcome IN ('done', 'unchanged') OR pages > 0))`,
		ref.CompanyID, ref.AccountID, resourceType, keyVersion.Truncate(time.Microsecond)).Scan(&ok); err != nil {
		return false, fmt.Errorf("store: served with key: %w", err)
	}
	return ok, nil
}

// HaltAllConnections останавливает выдачу задач всех подключений до
// ResumeAllConnections. Межарендаторная запись операционной задачи
// (CLAUDE.md §1, ADR-043 п. 5): ограничение доступа ложится на Client ID,
// и остановка действует на всех. Данных компании строка не несёт — только
// какое подключение и ресурс её вызвали. Повторная остановка не
// перезаписывает первую. halted — эта запись остановку поставила.
func (t *Tasks) HaltAllConnections(ctx context.Context, ref account.Ref, resourceType, reason string) (bool, error) {
	tag, err := t.pool.Exec(ctx, `
		INSERT INTO service_halt (company_id, marketplace_account_id, resource_type, reason) VALUES ($1, $2, $3, $4)
		ON CONFLICT (id) DO NOTHING`,
		ref.CompanyID, ref.AccountID, resourceType, reason)
	if err != nil {
		return false, fmt.Errorf("store: halt: %w", err)
	}
	return tag.RowsAffected() == 1, nil
}

// ResumeAllConnections снимает остановку всех подключений — только ручная
// команда -resume-halt (CLAUDE.md §1, операционная задача). resumed —
// остановка была.
func (t *Tasks) ResumeAllConnections(ctx context.Context) (bool, error) {
	tag, err := t.pool.Exec(ctx, `DELETE FROM service_halt`)
	if err != nil {
		return false, fmt.Errorf("store: resume halt: %w", err)
	}
	return tag.RowsAffected() > 0, nil
}

// PauseResource — пауза ресурса подключения до until; повтор продлевает её.
func (t *Tasks) PauseResource(ctx context.Context, ref account.Ref, resourceType string, until time.Time, reason string) error {
	if _, err := t.pool.Exec(ctx, `
		INSERT INTO resource_pause (company_id, marketplace_account_id, resource_type, paused_until, reason, updated_at) VALUES ($1, $2, $3, $4, $5, now())
		ON CONFLICT (company_id, marketplace_account_id, resource_type)
		DO UPDATE SET paused_until = EXCLUDED.paused_until, reason = EXCLUDED.reason, updated_at = now()`,
		ref.CompanyID, ref.AccountID, resourceType, until, reason); err != nil {
		return fmt.Errorf("store: pause resource: %w", err)
	}
	return nil
}
