<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Measured delivery maturity per account. Один posting считается один раз,
 * даже если в нём несколько SKU; future и отрицательные интервалы исключены.
 */
final readonly class BuyoutMaturityQuery
{
    public const int MIN_SAMPLE_SIZE = 30;

    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, string $marketplaceAccountId, \DateTimeImmutable $asOf): QueryBuilder
    {
        $intervals = <<<'SQL'
            SELECT DISTINCT company_id, marketplace_account_id, posting_number,
                   EXTRACT(EPOCH FROM (resolved_at - handed_over_at))::bigint AS duration_seconds
            FROM buyout_outcome
            WHERE company_id = :companyId
              AND marketplace_account_id = :accountId
              AND outcome IS NOT NULL
              AND posting_number IS NOT NULL
              AND handed_over_at IS NOT NULL
              AND resolved_at IS NOT NULL
              AND resolved_at >= handed_over_at
              AND resolved_at <= :asOf
            SQL;

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
