<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Buyout;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Measured cohort maturity per account (ADR-029): p95 от конца дня заказа
 * до наблюдённого закрытия. Один posting считается один раз, даже если
 * в нём несколько SKU; future-закрытия исключены.
 */
final readonly class BuyoutMaturityQuery
{
    public const int MIN_SAMPLE_SIZE = 30;

    /** Агрегат зрел, только если в доставке не больше 3% его количества. */
    public const int MAX_IN_FLIGHT_BPS = 300;

    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, string $marketplaceAccountId, \DateTimeImmutable $asOf): QueryBuilder
    {
        $intervals = self::postingIntervalsSql(<<<'SQL'
            (
                SELECT company_id, marketplace_account_id, posting_number, business_date,
                       outcome, resolved_at, resolution_observed
                FROM buyout_outcome
                WHERE company_id = :companyId
                  AND marketplace_account_id = :accountId
            ) account_outcome
            SQL);

        return $this->connection->createQueryBuilder()
            ->select(
                ':companyId AS company_id',
                ':accountId AS marketplace_account_id',
                'COUNT(*)::int AS sample_size',
                'PERCENTILE_DISC(0.50) WITHIN GROUP (ORDER BY duration_seconds) AS p50_seconds',
                'PERCENTILE_DISC(0.90) WITHIN GROUP (ORDER BY duration_seconds) AS p90_seconds',
                'CASE WHEN COUNT(*) >= '.self::MIN_SAMPLE_SIZE.' THEN PERCENTILE_DISC(0.95) WITHIN GROUP (ORDER BY duration_seconds) ELSE NULL END AS p95_seconds',
            )
            ->from('('.$intervals.')', 'intervals')
            ->setParameter('companyId', $companyId)
            ->setParameter('accountId', $marketplaceAccountId)
            ->setParameter('asOf', $asOf->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    /**
     * CTE `posting_intervals` и `maturity` поверх `tenant_outcome`; требует
     * параметр `:asOf` (UTC). Общие для списка, прогноза и дневного ряда.
     */
    public static function maturityCtes(): string
    {
        $intervals = self::postingIntervalsSql('tenant_outcome');
        $sample = self::MIN_SAMPLE_SIZE;

        return <<<SQL
            posting_intervals AS (
                {$intervals}
            ),
            maturity AS (
                SELECT marketplace_account_id,
                       CASE WHEN COUNT(*) >= {$sample}
                            THEN PERCENTILE_DISC(0.95) WITHIN GROUP (ORDER BY duration_seconds)
                            ELSE NULL
                       END AS p95_seconds
                FROM posting_intervals
                GROUP BY marketplace_account_id
            )
            SQL;
    }

    /**
     * CTE `training_days`: дни кабинета в 30-дневном окне перед границей
     * p95, зрелые на уровне кабинета (возраст и доля в доставке).
     * Требует `maturity`, `:asOf` (UTC) и `:asOfMoscow`.
     */
    public static function trainingDaysCte(): string
    {
        $inFlight = self::inFlightWithinLimitSql('o.quantity', 'o.is_in_flight');

        return <<<SQL
            training_days AS (
                SELECT o.marketplace_account_id, o.business_date
                FROM tenant_outcome o
                JOIN maturity m ON m.marketplace_account_id = o.marketplace_account_id
                WHERE m.p95_seconds IS NOT NULL
                  AND o.business_date < (:asOfMoscow::timestamp - make_interval(secs => m.p95_seconds::double precision))::date
                  AND o.business_date >= (:asOfMoscow::timestamp - make_interval(secs => m.p95_seconds::double precision))::date - INTERVAL '30 days'
                  AND EXTRACT(EPOCH FROM (
                      :asOf::timestamp - ((o.business_date + 1)::timestamp AT TIME ZONE 'Europe/Moscow' AT TIME ZONE 'UTC')
                  )) > m.p95_seconds
                GROUP BY o.marketplace_account_id, o.business_date
                HAVING {$inFlight}
            )
            SQL;
    }

    /** Агрегатное условие: доля количества в доставке не выше порога. */
    public static function inFlightWithinLimitSql(string $quantity, string $inFlight): string
    {
        return \sprintf(
            '10000 * COALESCE(SUM(%1$s) FILTER (WHERE %2$s), 0) <= %3$d * SUM(%1$s)',
            $quantity,
            $inFlight,
            self::MAX_IN_FLIGHT_BPS,
        );
    }

    private static function postingIntervalsSql(string $source): string
    {
        return <<<SQL
            SELECT company_id, marketplace_account_id, posting_number,
                   GREATEST(0, EXTRACT(EPOCH FROM (
                       MIN(resolved_at) - ((MIN(business_date) + 1)::timestamp AT TIME ZONE 'Europe/Moscow' AT TIME ZONE 'UTC')
                   )))::bigint AS duration_seconds
            FROM {$source}
            WHERE outcome IS NOT NULL
              AND posting_number IS NOT NULL
              AND resolved_at IS NOT NULL
              AND resolution_observed
              AND resolved_at <= :asOf
            GROUP BY company_id, marketplace_account_id, posting_number
            SQL;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): BuyoutMaturityRow
    {
        return new BuyoutMaturityRow(
            companyId: self::string($row['company_id'] ?? null),
            marketplaceAccountId: self::string($row['marketplace_account_id'] ?? null),
            sampleSize: self::integer($row['sample_size'] ?? null),
            p50Seconds: self::nullableInteger($row['p50_seconds'] ?? null),
            p90Seconds: self::nullableInteger($row['p90_seconds'] ?? null),
            p95Seconds: self::nullableInteger($row['p95_seconds'] ?? null),
        );
    }

    private static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected string in buyout maturity row.');
        }

        return $value;
    }

    private static function integer(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('Expected integer in buyout maturity row.');
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return null === $value ? null : self::integer($value);
    }
}
