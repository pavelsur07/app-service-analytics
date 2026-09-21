<?php

declare(strict_types=1);

namespace App\Planning\Infrastructure\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

/**
 * Операционная межарендаторная очистка временных preview (CLAUDE.md §1).
 *
 * Запрос намеренно обходит все компании, не возвращает пользовательские
 * данные и доступен только узкому action консольной команды через Deptrac.
 */
final readonly class CrossTenantExpiredPlanImportPreviewCleaner
{
    public function __construct(private Connection $connection)
    {
    }

    public function deleteExpiredAcrossCompanies(\DateTimeImmutable $now, int $limit = 1_000): int
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new \InvalidArgumentException('Лимит очистки должен быть от 1 до 1000.');
        }

        $deleted = $this->connection->executeStatement(<<<'SQL'
            WITH ready AS (
              SELECT id, expires_at AS sort_at
              FROM planning_import_preview
              WHERE status = 'ready' AND expires_at <= :now
              ORDER BY expires_at, id
              LIMIT :limit
              FOR UPDATE SKIP LOCKED
            ), applied AS (
              SELECT id, applied_at AS sort_at
              FROM planning_import_preview
              WHERE status = 'applied' AND applied_at <= :appliedCutoff
              ORDER BY applied_at, id
              LIMIT :limit
              FOR UPDATE SKIP LOCKED
            ), doomed AS (
              SELECT id FROM (
                SELECT id, sort_at FROM ready
                UNION ALL
                SELECT id, sort_at FROM applied
              ) candidates
              ORDER BY sort_at, id
              LIMIT :limit
            )
            DELETE FROM planning_import_preview preview
            USING doomed
            WHERE preview.id = doomed.id
            SQL,
            ['now' => $now, 'appliedCutoff' => $now->modify('-30 days'), 'limit' => $limit],
            ['now' => Types::DATETIME_IMMUTABLE, 'appliedCutoff' => Types::DATETIME_IMMUTABLE, 'limit' => ParameterType::INTEGER],
        );
        if (!\is_int($deleted)) {
            throw new \UnexpectedValueException('База вернула некорректное число удалённых preview.');
        }

        return $deleted;
    }
}
