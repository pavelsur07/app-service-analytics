<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Причины terminal/unknown строк без канонического outcome.
 * Обычные pending-статусы исключены, чтобы мониторинг не тонул в нормальном
 * незавершённом хвосте; terminal cancellation остаётся видна всегда.
 */
final readonly class UnclassifiedOzonBuyoutReasonsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, string $marketplaceAccountId, int $limit): QueryBuilder
    {
        $source = <<<'SQL'
            WITH latest_status AS (
                SELECT DISTINCT ON (company_id, marketplace_account_id, posting_number)
                       company_id, marketplace_account_id, posting_number,
                       status, substatus, cancel_reason_id
                FROM marketplace_posting_status
                ORDER BY company_id, marketplace_account_id, posting_number,
                         observed_at DESC, raw_document_id DESC
            ),
            return_totals AS (
                SELECT company_id, marketplace_account_id, order_number, marketplace_sku,
                       SUM(quantity)::bigint AS return_quantity
                FROM marketplace_return_fact
                WHERE company_id = :companyId
                  AND marketplace_account_id = :accountId
                GROUP BY company_id, marketplace_account_id, order_number, marketplace_sku
            ),
            sales_totals AS (
                SELECT company_id, marketplace_account_id, order_number, marketplace_sku,
                       SUM(quantity)::bigint AS sales_quantity
                FROM sales_fact
                WHERE company_id = :companyId
                  AND marketplace_account_id = :accountId
                  AND order_number IS NOT NULL
                GROUP BY company_id, marketplace_account_id, order_number, marketplace_sku
            )
            SELECT r.return_type,
                   r.return_reason_name,
                   l.status,
                   l.substatus,
                   l.cancel_reason_id,
                   COUNT(DISTINCT (s.marketplace_account_id, s.source_row_id))::int AS affected_rows,
                   MIN(s.business_date) AS first_business_date,
                   MAX(s.business_date) AS last_business_date
            FROM buyout_outcome o
            JOIN sales_fact s
              ON s.company_id = o.company_id
             AND s.marketplace_account_id = o.marketplace_account_id
             AND s.source_row_id = o.source_row_id
            LEFT JOIN latest_status l
              ON l.company_id = s.company_id
             AND l.marketplace_account_id = s.marketplace_account_id
             AND l.posting_number = s.posting_number
            LEFT JOIN marketplace_return_fact r
              ON r.company_id = s.company_id
             AND r.marketplace_account_id = s.marketplace_account_id
             AND r.order_number = s.order_number
             AND r.marketplace_sku = s.marketplace_sku
            LEFT JOIN ozon_return_reason_classification c
              ON c.return_type = r.return_type
             AND c.return_reason_name = r.return_reason_name
            LEFT JOIN return_totals rt
              ON rt.company_id = s.company_id
             AND rt.marketplace_account_id = s.marketplace_account_id
             AND rt.order_number = s.order_number
             AND rt.marketplace_sku = s.marketplace_sku
            LEFT JOIN sales_totals st
              ON st.company_id = s.company_id
             AND st.marketplace_account_id = s.marketplace_account_id
             AND st.order_number = s.order_number
             AND st.marketplace_sku = s.marketplace_sku
            WHERE o.company_id = :companyId
              AND o.marketplace_account_id = :accountId
              AND (
                  (
                      o.outcome IS NULL
                      AND NOT COALESCE(
                          (l.status = 'awaiting_packaging' AND l.substatus = 'posting_created')
                          OR (l.status = 'awaiting_deliver' AND l.substatus = 'posting_transferring_to_delivery')
                          OR (l.status = 'delivering' AND l.substatus IN ('posting_in_pickup_point', 'posting_on_way_to_city')),
                          FALSE
                      )
                  )
                  OR (
                      r.return_type = 'Cancellation'
                      AND c.return_type IS NULL
                  )
                  OR COALESCE(rt.return_quantity, 0) > COALESCE(st.sales_quantity, 0)
                  OR (
                      COALESCE(rt.return_quantity, 0) > 0
                      AND COALESCE(
                          (l.status = 'awaiting_packaging' AND l.substatus = 'posting_created')
                          OR (l.status = 'awaiting_deliver' AND l.substatus = 'posting_transferring_to_delivery')
                          OR (l.status = 'delivering' AND l.substatus IN ('posting_in_pickup_point', 'posting_on_way_to_city')),
                          FALSE
                      )
                  )
              )
            GROUP BY r.return_type, r.return_reason_name,
                     l.status, l.substatus, l.cancel_reason_id
            SQL;

        return $this->connection->createQueryBuilder()
            ->select(
                'return_type',
                'return_reason_name',
                'status',
                'substatus',
                'cancel_reason_id',
                'affected_rows',
                'first_business_date',
                'last_business_date',
            )
            ->from('('.$source.')', 'unclassified')
            ->setParameter('companyId', $companyId)
            ->setParameter('accountId', $marketplaceAccountId)
            ->orderBy('affected_rows', 'DESC')
            ->addOrderBy('return_type', 'ASC')
            ->addOrderBy('return_reason_name', 'ASC')
            ->setMaxResults($limit);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): UnclassifiedOzonBuyoutReasonRow
    {
        return new UnclassifiedOzonBuyoutReasonRow(
            returnType: self::nullableString($row['return_type'] ?? null),
            returnReasonName: self::nullableString($row['return_reason_name'] ?? null),
            status: self::nullableString($row['status'] ?? null),
            substatus: self::nullableString($row['substatus'] ?? null),
            cancelReasonId: self::nullableInteger($row['cancel_reason_id'] ?? null),
            affectedRows: self::integer($row['affected_rows'] ?? null),
            firstBusinessDate: self::string($row['first_business_date'] ?? null),
            lastBusinessDate: self::string($row['last_business_date'] ?? null),
        );
    }

    private static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected string in unclassified buyout reason row.');
        }

        return $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        return null === $value ? null : self::string($value);
    }

    private static function integer(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('Expected integer in unclassified buyout reason row.');
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return null === $value ? null : self::integer($value);
    }
}
