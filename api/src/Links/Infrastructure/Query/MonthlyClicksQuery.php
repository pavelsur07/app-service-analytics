<?php

declare(strict_types=1);

namespace App\Links\Infrastructure\Query;

use Doctrine\DBAL\Connection;

final readonly class MonthlyClicksQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function linkExists(string $linkId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM short_link WHERE id = :linkId LIMIT 1',
            ['linkId' => $linkId],
        );
    }

    /**
     * @return list<DailyClicksRow>
     */
    public function fetch(
        string $linkId,
        \DateTimeImmutable $start,
        \DateTimeImmutable $endExclusive,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT CAST(clicked_at AS DATE) AS day, COUNT(*) AS clicks
                FROM short_link_click
                WHERE short_link_id = :linkId
                  AND clicked_at >= :start
                  AND clicked_at < :endExclusive
                  AND is_bot = FALSE
                GROUP BY CAST(clicked_at AS DATE)
                ORDER BY day ASC
                SQL,
            [
                'linkId' => $linkId,
                'start' => $start->format('Y-m-d H:i:s'),
                'endExclusive' => $endExclusive->format('Y-m-d H:i:s'),
            ],
        );

        return array_map(self::mapRow(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function mapRow(array $row): DailyClicksRow
    {
        $day = $row['day'] ?? null;
        $clicks = $row['clicks'] ?? null;
        if (!\is_string($day)) {
            throw new \UnexpectedValueException('Expected a date in daily clicks row.');
        }
        if (!\is_int($clicks) && !(\is_string($clicks) && 1 === preg_match('/^\d+$/D', $clicks))) {
            throw new \UnexpectedValueException('Expected an integer in daily clicks row.');
        }

        return new DailyClicksRow($day, (int) $clicks);
    }
}
