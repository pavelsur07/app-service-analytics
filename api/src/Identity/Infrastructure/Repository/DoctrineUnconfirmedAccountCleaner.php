<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\AuditAction;
use App\Identity\Domain\UnconfirmedAccountCleaner;
use Doctrine\DBAL\Connection;

/**
 * Операционная межмодульная проверка уборки (CLAUDE.md §1, ADR-020).
 *
 * Запрос намеренно знает все таблицы, наличие данных в которых запрещает
 * удаление арендатора. Это единая ручная операция Identity, Ingestion и
 * PriceMonitoring, а не переиспользуемый способ читать чужие данные из
 * seller HTTP. Deptrac держит класс в отдельном слое, доступном только
 * purge-action и его консольной команде.
 */
final readonly class DoctrineUnconfirmedAccountCleaner implements UnconfirmedAccountCleaner
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function purgeCreatedBefore(\DateTimeImmutable $cutoff): int
    {
        $deleted = $this->connection->fetchOne(
            <<<'SQL'
                WITH eligible_company AS MATERIALIZED (
                    SELECT c.id
                    FROM company c
                    WHERE c.created_at < :cutoff
                      AND NOT EXISTS (
                          SELECT 1
                          FROM company_member cm
                          JOIN "user" u ON u.id = cm.user_id
                          WHERE cm.company_id = c.id
                            AND u.email_confirmed_at IS NOT NULL
                      )
                      AND NOT EXISTS (SELECT 1 FROM marketplace_account ma WHERE ma.company_id = c.id)
                      AND NOT EXISTS (SELECT 1 FROM sales_fact sf WHERE sf.company_id = c.id)
                      AND NOT EXISTS (SELECT 1 FROM marketplace_expense_fact mef WHERE mef.company_id = c.id)
                      AND NOT EXISTS (SELECT 1 FROM marketplace_raw_document mrd WHERE mrd.company_id = c.id)
                      AND NOT EXISTS (SELECT 1 FROM marketplace_listing ml WHERE ml.company_id = c.id)
                      AND NOT EXISTS (SELECT 1 FROM marketplace_listing_cost mlc WHERE mlc.company_id = c.id)
                      AND NOT EXISTS (SELECT 1 FROM marketplace_listing_price mlp WHERE mlp.company_id = c.id)
                      AND NOT EXISTS (SELECT 1 FROM tracked_sku ts WHERE ts.company_id = c.id)
                      AND NOT EXISTS (SELECT 1 FROM price_observation po WHERE po.company_id = c.id)
                      AND NOT EXISTS (
                          SELECT 1
                          FROM audit_record ar
                          WHERE ar.company_id = c.id
                            AND ar.action <> :registration_action
                      )
                    FOR UPDATE
                ),
                eligible_user AS MATERIALIZED (
                    SELECT DISTINCT cm.user_id
                    FROM company_member cm
                    JOIN eligible_company ec ON ec.id = cm.company_id
                    JOIN "user" u ON u.id = cm.user_id
                    WHERE u.email_confirmed_at IS NULL
                      AND NOT EXISTS (
                          SELECT 1
                          FROM company_member retained
                          WHERE retained.user_id = cm.user_id
                            AND NOT EXISTS (
                                SELECT 1
                                FROM eligible_company retained_eligible
                                WHERE retained_eligible.id = retained.company_id
                            )
                      )
                ),
                deleted_tokens AS (
                    DELETE FROM email_verification_token token
                    USING eligible_user eu
                    WHERE token.user_id = eu.user_id
                    RETURNING token.id
                ),
                deleted_audit AS (
                    DELETE FROM audit_record audit
                    USING eligible_company ec
                    WHERE audit.company_id = ec.id
                      AND audit.action = :registration_action
                    RETURNING audit.id
                ),
                deleted_memberships AS (
                    DELETE FROM company_member member
                    USING eligible_company ec
                    WHERE member.company_id = ec.id
                    RETURNING member.user_id
                ),
                deleted_users AS (
                    DELETE FROM "user" u
                    USING eligible_user eu
                    WHERE u.id = eu.user_id
                      AND u.email_confirmed_at IS NULL
                    RETURNING u.id
                ),
                deleted_companies AS (
                    DELETE FROM company c
                    USING eligible_company ec
                    WHERE c.id = ec.id
                    RETURNING c.id
                )
                SELECT count(*) FROM deleted_companies
                SQL,
            [
                'cutoff' => $cutoff->format('Y-m-d H:i:s'),
                'registration_action' => AuditAction::CompanyRegistered,
            ],
        );

        if (!\is_int($deleted) && !\is_string($deleted)) {
            throw new \UnexpectedValueException('Expected deleted company count to be numeric.');
        }

        return (int) $deleted;
    }
}
