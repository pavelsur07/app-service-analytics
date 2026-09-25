<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Buyout;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Первая загрузка возвратов компании (ADR-030): раньше классификация
 * T1/T2/P на прошлую дату неполна, и проверка прогноза туда не заходит.
 */
final readonly class BuyoutBacktestStartQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('MIN(first_loaded_at) AS first_returns_loaded_at')
            ->from('marketplace_return_fact')
            ->where('company_id = :companyId')
            ->setParameter('companyId', $companyId);
    }

    /** @param array<string, mixed> $row */
    public static function mapRow(array $row): ?\DateTimeImmutable
    {
        $value = $row['first_returns_loaded_at'] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected timestamp in buyout backtest start row.');
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
