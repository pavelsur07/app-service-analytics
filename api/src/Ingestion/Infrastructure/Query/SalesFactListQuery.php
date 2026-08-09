<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Список продаж — DBAL, не гидрация SalesFact (CLAUDE.md §5). build()
 * отдаёт QueryBuilder, не массив (CLAUDE.md §5) — выполнение и сборка
 * результата в DTO — дело вызывающего кода (контроллера).
 *
 * Курсор, не offset: sales_fact растёт с объёмом данных клиента
 * (docs/patterns.md, «Пагинация»). Сортировка business_date DESC (свежее
 * сверху) однозначна за счёт (marketplace_account_id, source_row_id) тем
 * же направлением — иначе кортеж-сравнение курсора не соответствовало бы
 * фактическому порядку строк.
 */
final readonly class SalesFactListQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function build(string $companyId, ?string $cursor, int $limit): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder()
            ->select(
                'marketplace_account_id',
                'source_row_id',
                'business_date',
                'status',
                'marketplace_sku',
                'quantity',
                'amount_minor',
                'commission_amount_minor',
                'currency',
            )
            ->from('sales_fact')
            ->where('company_id = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('business_date', 'DESC')
            ->addOrderBy('marketplace_account_id', 'DESC')
            ->addOrderBy('source_row_id', 'DESC')
            // +1 — узнать, есть ли следующая страница, без отдельного COUNT(*)
            // (который на факт-таблицах не выполняется, CLAUDE.md §5).
            ->setMaxResults($limit + 1);

        if (null !== $cursor) {
            [$cursorDate, $cursorAccountId, $cursorSourceRowId] = self::decodeCursor($cursor);
            $qb->andWhere('(business_date, marketplace_account_id, source_row_id) < (:cursorDate, :cursorAccountId, :cursorSourceRowId)')
                ->setParameter('cursorDate', $cursorDate)
                ->setParameter('cursorAccountId', $cursorAccountId)
                ->setParameter('cursorSourceRowId', $cursorSourceRowId);
        }

        return $qb;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): SalesFactListRow
    {
        return new SalesFactListRow(
            marketplaceAccountId: self::stringValue($row['marketplace_account_id']),
            sourceRowId: self::stringValue($row['source_row_id']),
            businessDate: self::stringValue($row['business_date']),
            status: self::stringValue($row['status']),
            marketplaceSku: self::stringValue($row['marketplace_sku']),
            quantity: self::intValue($row['quantity']),
            amountMinor: self::intValue($row['amount_minor']),
            commissionAmountMinor: self::intValue($row['commission_amount_minor']),
            currency: self::stringValue($row['currency']),
        );
    }

    public static function encodeCursor(SalesFactListRow $row): string
    {
        return base64_encode(json_encode(
            [$row->businessDate, $row->marketplaceAccountId, $row->sourceRowId],
            \JSON_THROW_ON_ERROR,
        ));
    }

    private static function stringValue(mixed $value): string
    {
        if (!\is_string($value) && !\is_int($value)) {
            throw new \UnexpectedValueException('Expected a string-like value in a sales_fact row.');
        }

        return (string) $value;
    }

    private static function intValue(mixed $value): int
    {
        if (!\is_int($value) && !\is_string($value)) {
            throw new \UnexpectedValueException('Expected an int-like value in a sales_fact row.');
        }

        return (int) $value;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private static function decodeCursor(string $cursor): array
    {
        $decoded = base64_decode($cursor, true);
        if (false === $decoded) {
            throw new \InvalidArgumentException('Cursor is not valid base64.');
        }

        $parsed = json_decode($decoded, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($parsed) || 3 !== \count($parsed) || !\is_string($parsed[0]) || !\is_string($parsed[1]) || !\is_string($parsed[2])) {
            throw new \InvalidArgumentException('Cursor does not decode to a 3-tuple of strings.');
        }

        return [$parsed[0], $parsed[1], $parsed[2]];
    }
}
