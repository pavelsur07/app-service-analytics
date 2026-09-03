<?php

declare(strict_types=1);

namespace App\Links\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class AllShortLinksForAdminQuery
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 200;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function build(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('id', 'code', 'name', 'target_url', 'status', 'version', 'created_at', 'updated_at')
            ->from('short_link')
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC');
    }

    public function countAll(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM short_link');
        if (!is_numeric($count)) {
            throw new \UnexpectedValueException('Expected a numeric count of short links.');
        }

        return (int) $count;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): AdminShortLinkRow
    {
        return new AdminShortLinkRow(
            id: self::stringValue($row, 'id'),
            code: self::stringValue($row, 'code'),
            name: self::stringValue($row, 'name'),
            targetUrl: self::stringValue($row, 'target_url'),
            status: self::stringValue($row, 'status'),
            version: self::intValue($row, 'version'),
            createdAt: self::dateValue($row, 'created_at'),
            updatedAt: self::dateValue($row, 'updated_at'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function stringValue(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!\is_string($value)) {
            throw new \UnexpectedValueException("Expected a string in short link column {$key}.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function intValue(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!\is_int($value) && !(\is_string($value) && 1 === preg_match('/^\d+$/D', $value))) {
            throw new \UnexpectedValueException("Expected an integer in short link column {$key}.");
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function dateValue(array $row, string $key): string
    {
        $value = self::stringValue($row, $key);

        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format(\DATE_ATOM);
    }
}
