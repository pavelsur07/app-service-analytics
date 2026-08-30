<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Ingestion\Domain\BuyoutOutcome;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** Tenant-scoped чтение вычисляемой view buyout_outcome. */
final readonly class BuyoutOutcomeQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, string $marketplaceAccountId, int $limit): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'company_id',
                'marketplace_account_id',
                'source_row_id',
                'posting_number',
                'order_number',
                'marketplace_sku',
                'quantity',
                'business_date',
                'outcome',
                'handed_over_at',
                'resolved_at',
            )
            ->from('buyout_outcome')
            ->where('company_id = :companyId')
            ->andWhere('marketplace_account_id = :accountId')
            ->setParameter('companyId', $companyId)
            ->setParameter('accountId', $marketplaceAccountId)
            ->orderBy('marketplace_sku', 'ASC')
            ->addOrderBy('source_row_id', 'ASC')
            ->setMaxResults($limit);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): BuyoutOutcomeRow
    {
        $outcome = self::nullableString($row['outcome'] ?? null);
        if (null !== $outcome) {
            BuyoutOutcome::from($outcome);
        }

        return new BuyoutOutcomeRow(
            companyId: self::string($row['company_id'] ?? null),
            marketplaceAccountId: self::string($row['marketplace_account_id'] ?? null),
            sourceRowId: self::string($row['source_row_id'] ?? null),
            postingNumber: self::nullableString($row['posting_number'] ?? null),
            orderNumber: self::nullableString($row['order_number'] ?? null),
            marketplaceSku: self::string($row['marketplace_sku'] ?? null),
            quantity: self::integer($row['quantity'] ?? null),
            businessDate: self::string($row['business_date'] ?? null),
            outcome: $outcome,
            handedOverAt: self::nullableString($row['handed_over_at'] ?? null),
            resolvedAt: self::nullableString($row['resolved_at'] ?? null),
        );
    }

    private static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected string in buyout outcome row.');
        }

        return $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        return self::string($value);
    }

    private static function integer(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('Expected integer in buyout outcome row.');
    }
}
