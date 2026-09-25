<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Buyout;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Конец первичной загрузки возвратов компании (ADR-030): последний
 * возврат, впервые загруженный в течение FIRST_LOAD_WINDOW от самого
 * первого. До конца этой волны классификация T1/T2/P на прошлую дату
 * неполна, и проверка прогноза туда не заходит.
 */
final readonly class BuyoutBacktestStartQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public const string FIRST_LOAD_WINDOW = '48 hours';

    public function build(string $companyId): QueryBuilder
    {
        $window = self::FIRST_LOAD_WINDOW;

        return $this->connection->createQueryBuilder()
            ->select('MAX(r.first_loaded_at) AS first_returns_loaded_at')
            ->from('marketplace_return_fact', 'r')
            ->where('r.company_id = :companyId')
            ->andWhere(<<<SQL
                r.first_loaded_at <= (
                    SELECT MIN(first_loaded_at) + INTERVAL '{$window}'
                    FROM marketplace_return_fact
                    WHERE company_id = :companyId
                )
                SQL)
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
