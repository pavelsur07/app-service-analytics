<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Юнит-экономика за период: по товару — выручка, комиссия и расходы
 * площадки; отдельно — расходы кабинета, к товару не привязанные.
 *
 * Считает PostgreSQL, не PHP (CLAUDE.md §5): наружу уходят агрегаты,
 * а не выборка фактов.
 *
 * Товары берутся объединением продаж и расходов — FULL OUTER JOIN
 * по артикулу, а не по дате. Дата тут соединять нечего: у продажи
 * и её расходов разные бизнес-даты по природе, начисление приходит
 * позже, иногда на недели (ADR-012). Обе стороны уже свёрнуты за один
 * и тот же период, и склеиваются по товару.
 *
 * Объединение в SQL, а не в PHP, ещё и потому, что иначе список товаров
 * невозможно ограничить: артикул с расходами, но без продаж, попадал бы
 * в ответ мимо лимита, и отчёт превышал бы собственный потолок.
 *
 * Пагинация курсорная (§5): число артикулов растёт с каталогом клиента.
 * Курсор — пара «выручка, артикул»: сортировка по одной выручке
 * неустойчива, у товаров без продаж она нулевая у всех.
 */
final readonly class UnitEconomicsQuery
{
    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 200;

    /**
     * Типов начислений у кабинета порядка десятка, и разбивка берётся
     * только по артикулам текущей страницы — потолок здесь защитный.
     */
    private const int MAX_BREAKDOWN_ROWS = 4000;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Страница товаров: выручка и комиссия из продаж, итог расходов —
     * из расходов, обе стороны за один период.
     *
     * $cursor — пара из предыдущей страницы; null для первой.
     */
    public function skus(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit,
        ?UnitEconomicsCursor $cursor,
    ): QueryBuilder {
        $sales = <<<'SQL'
            SELECT marketplace_sku,
                   currency,
                   COALESCE(SUM(quantity) FILTER (WHERE status = 'delivered'), 0) AS delivered_quantity,
                   COALESCE(SUM(amount_minor) FILTER (WHERE status = 'delivered'), 0) AS delivered_amount_minor,
                   COALESCE(SUM(commission_amount_minor) FILTER (WHERE status = 'delivered'), 0) AS commission_amount_minor,
                   COALESCE(SUM(quantity) FILTER (WHERE status <> 'cancelled'), 0) AS ordered_quantity
            FROM sales_fact
            WHERE company_id = :companyId AND business_date >= :from AND business_date <= :to
            GROUP BY marketplace_sku, currency
            SQL;

        $expenses = <<<'SQL'
            SELECT marketplace_sku,
                   currency,
                   SUM(amount_minor) AS expenses_total_minor
            FROM marketplace_expense_fact
            WHERE company_id = :companyId AND business_date >= :from AND business_date <= :to
              AND marketplace_sku <> ''
            GROUP BY marketplace_sku, currency
            SQL;

        $joined = <<<SQL
            (
                SELECT COALESCE(s.marketplace_sku, e.marketplace_sku) AS marketplace_sku,
                       COALESCE(s.currency, e.currency) AS currency,
                       COALESCE(s.delivered_quantity, 0) AS delivered_quantity,
                       COALESCE(s.delivered_amount_minor, 0) AS delivered_amount_minor,
                       COALESCE(s.commission_amount_minor, 0) AS commission_amount_minor,
                       COALESCE(s.ordered_quantity, 0) AS ordered_quantity,
                       COALESCE(e.expenses_total_minor, 0) AS expenses_total_minor
                FROM ({$sales}) AS s
                FULL OUTER JOIN ({$expenses}) AS e
                  ON s.marketplace_sku = e.marketplace_sku AND s.currency = e.currency
            ) AS sku
            SQL;

        $qb = $this->connection->createQueryBuilder()
            ->select(
                'marketplace_sku',
                'currency',
                'delivered_quantity',
                'delivered_amount_minor',
                'commission_amount_minor',
                'ordered_quantity',
                'expenses_total_minor',
            )
            ->from($joined)
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            // Сначала то, что приносит больше всего: ради этого экран
            // и открывают. Артикул вторым столбцом — чтобы порядок был
            // устойчивым, иначе курсор перескакивал бы строки.
            ->orderBy('delivered_amount_minor', 'DESC')
            ->addOrderBy('marketplace_sku', 'ASC')
            // +1 — узнать, есть ли следующая страница, без COUNT(*)
            // на факт-таблице (§5).
            ->setMaxResults($limit + 1);

        if (null !== $cursor) {
            $qb->andWhere($cursor->after('delivered_amount_minor'))
                ->setParameter('cursorAmount', $cursor->deliveredAmountMinor)
                ->setParameter('cursorSku', $cursor->marketplaceSku);
        }

        return $qb;
    }

    /**
     * Разбивка расходов по типам — только для артикулов страницы.
     * Пустой список артикулов не запрашивается вовсе: вызывающий код
     * до этого не доходит.
     *
     * @param list<string> $marketplaceSkus
     */
    public function breakdown(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $marketplaceSkus,
    ): QueryBuilder {
        return $this->connection->createQueryBuilder()
            ->select('marketplace_sku', 'fee_type_id', 'currency', 'SUM(amount_minor) AS amount_minor')
            ->from('marketplace_expense_fact')
            ->where('company_id = :companyId')
            ->andWhere('business_date >= :from')
            ->andWhere('business_date <= :to')
            ->andWhere('marketplace_sku IN (SELECT jsonb_array_elements_text(:skus::jsonb))')
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('skus', json_encode($marketplaceSkus, \JSON_THROW_ON_ERROR))
            ->groupBy('marketplace_sku')
            ->addGroupBy('fee_type_id')
            ->addGroupBy('currency')
            ->setMaxResults(self::MAX_BREAKDOWN_ROWS);
    }

    /**
     * Расходы кабинета: реклама, хранение, досрочная выплата. Не
     * размазываются по товарам (ADR-012) — базис распределения захочется
     * менять, а показанная строка честнее доли, происхождение которой
     * клиент не проверит.
     */
    public function cabinetExpenses(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select("'' AS marketplace_sku", 'fee_type_id', 'currency', 'SUM(amount_minor) AS amount_minor')
            ->from('marketplace_expense_fact')
            ->where('company_id = :companyId')
            ->andWhere('business_date >= :from')
            ->andWhere('business_date <= :to')
            ->andWhere("marketplace_sku = ''")
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->groupBy('fee_type_id')
            ->addGroupBy('currency')
            // Типов начислений у площадки 119 — потолок с запасом
            // и всё равно ограничен (§5).
            ->setMaxResults(self::MAX_LIMIT);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapSkuRow(array $row): UnitEconomicsSkuRow
    {
        return new UnitEconomicsSkuRow(
            marketplaceSku: self::stringValue($row['marketplace_sku']),
            currency: self::stringValue($row['currency']),
            deliveredQuantity: self::intValue($row['delivered_quantity']),
            deliveredAmountMinor: self::intValue($row['delivered_amount_minor']),
            commissionAmountMinor: self::intValue($row['commission_amount_minor']),
            orderedQuantity: self::intValue($row['ordered_quantity']),
            expensesTotalMinor: self::intValue($row['expenses_total_minor']),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapExpenseRow(array $row): UnitEconomicsExpenseRow
    {
        return new UnitEconomicsExpenseRow(
            marketplaceSku: self::stringValue($row['marketplace_sku']),
            feeTypeId: self::intValue($row['fee_type_id']),
            currency: self::stringValue($row['currency']),
            amountMinor: self::intValue($row['amount_minor']),
        );
    }

    private static function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string value in a unit economics row.');
        }

        return $value;
    }

    private static function intValue(mixed $value): int
    {
        // SUM в PostgreSQL возвращает numeric, и DBAL отдаёт его строкой:
        // приводим явно, а не полагаемся на то, что драйвер угадает.
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && 1 === preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('Expected an integer value in a unit economics row.');
    }
}
