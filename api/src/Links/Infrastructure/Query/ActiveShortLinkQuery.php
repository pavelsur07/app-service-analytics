<?php

declare(strict_types=1);

namespace App\Links\Infrastructure\Query;

use Doctrine\DBAL\Connection;

final readonly class ActiveShortLinkQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function find(string $code): ?RedirectTarget
    {
        $row = $this->connection->createQueryBuilder()
            ->select('id', 'code', 'target_url')
            ->from('short_link')
            ->where('code = :code')
            ->andWhere("status = 'active'")
            ->setParameter('code', $code)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return new RedirectTarget(
            id: self::stringValue($row, 'id'),
            code: self::stringValue($row, 'code'),
            targetUrl: self::stringValue($row, 'target_url'),
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
}
