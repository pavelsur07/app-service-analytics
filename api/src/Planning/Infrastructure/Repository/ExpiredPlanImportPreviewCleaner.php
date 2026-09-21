<?php

declare(strict_types=1);

namespace App\Planning\Infrastructure\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

final readonly class ExpiredPlanImportPreviewCleaner
{
    public function __construct(private Connection $connection)
    {
    }

    public function delete(\DateTimeImmutable $now, int $limit = 1_000): int
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new \InvalidArgumentException('Лимит очистки должен быть от 1 до 1000.');
        }

        $deleted = $this->connection->executeStatement(<<<'SQL'
            WITH doomed AS (
              SELECT id
              FROM planning_import_preview
              WHERE (status = 'ready' AND expires_at <= :now)
                 OR (status = 'applied' AND applied_at <= :appliedCutoff)
              ORDER BY expires_at, id
              LIMIT :limit
              FOR UPDATE SKIP LOCKED
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
