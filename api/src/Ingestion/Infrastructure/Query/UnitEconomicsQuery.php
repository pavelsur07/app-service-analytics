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
 * Курсор — пара «значение сортировки, артикул»: сортировка по одному
 * показателю неустойчива, у товаров без продаж он нулевой у всех.
 *
 * Порядок выбирает клиент из шести числовых показателей. Индекса под него
 * нет и быть не может: сортировка идёт по агрегату двух таблиц фактов
 * за окно, который строится на лету. Стоимость запроса определяется
 * агрегацией, а не сортировкой, и от выбранной колонки не зависит.
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
        UnitEconomicsSort $sort,
        UnitEconomicsDirection $direction,
        ?UnitEconomicsCursor $cursor,
    ): QueryBuilder {
        // Курсор строит условие по своей колонке, а ORDER BY идёт
        // по запрошенной. Разойдись они — выборка отсекалась бы по одному
        // показателю, а упорядочивалась по другому: страница вышла бы
        // правдоподобной и неверной, и никто бы не заметил. HTTP-граница
        // это уже проверяет, но сценарий публичный, и проверка на границе
        // проверкой здесь не является.
        if (null !== $cursor && !$cursor->matches($sort, $direction)) {
            throw new \InvalidArgumentException('Cursor was issued for a different sort order.');
        }

        // Себестоимость соединяется с КАЖДОЙ строкой продажи по её
        // бизнес-дате, а не берётся одна на период (ADR-013). Иначе
        // товар, проданный в июле по 420, посчитался бы по августовской
        // цене 510 — то есть новая поставка задним числом переписала бы
        // прибыль за июль, ровно то, что раздельные операции ввода
        // и запрещают.
        //
        // Умножение цены на количество делает PostgreSQL над минорными
        // единицами (§5, ADR-013): у Money умножения нет, а количество
        // целое, и округлять здесь нечего.
        //
        // Знак отрицательный намеренно — как у комиссии и расходов
        // площадки. Тогда итог везде складывается, и ни одна строка
        // расчёта не гадает, каким знаком пришла величина.
        $sales = <<<'SQL'
            SELECT f.marketplace_sku,
                   f.currency,
                   COALESCE(SUM(f.quantity) FILTER (WHERE f.status = 'delivered'), 0) AS delivered_quantity,
                   COALESCE(SUM(f.amount_minor) FILTER (WHERE f.status = 'delivered'), 0) AS delivered_amount_minor,
                   COALESCE(SUM(f.commission_amount_minor) FILTER (WHERE f.status = 'delivered'), 0) AS commission_amount_minor,
                   COALESCE(SUM(f.quantity) FILTER (WHERE f.status <> 'cancelled'), 0) AS ordered_quantity,
                   -COALESCE(SUM(f.quantity * c.unit_cost_minor) FILTER (WHERE f.status = 'delivered'), 0) AS cost_total_minor,
                   COALESCE(SUM(f.quantity) FILTER (WHERE f.status = 'delivered' AND c.unit_cost_minor IS NULL), 0) AS quantity_without_cost,
                   MAX(c.updated_at) FILTER (WHERE f.status = 'delivered' AND c.updated_at > c.recorded_at) AS cost_corrected_at
            FROM sales_fact AS f
            LEFT JOIN LATERAL (
                SELECT lc.unit_cost_minor, lc.updated_at, lc.recorded_at
                FROM marketplace_listing_cost AS lc
                WHERE lc.company_id = f.company_id
                  AND lc.marketplace_account_id = f.marketplace_account_id
                  AND lc.marketplace_sku = f.marketplace_sku
                  AND lc.effective_from <= f.business_date
                ORDER BY lc.effective_from DESC
                LIMIT 1
            ) AS c ON TRUE
            WHERE f.company_id = :companyId AND f.business_date >= :from AND f.business_date <= :to
            GROUP BY f.marketplace_sku, f.currency
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

        // Карточка товара: название и артикул селлера. Ключ каталога —
        // (company_id, marketplace_account_id, marketplace_sku), а агрегат
        // выше схлопнут по (marketplace_sku, currency) и подключения уже
        // не знает. Артикул площадки уникален в пределах подключения,
        // а не площадки вообще, поэтому join по company_id + артикулу мог
        // бы вернуть две карточки на одну строку расчёта и задвоить
        // страницу — под лимитом пришло бы меньше товаров, чем обещано.
        //
        // DISTINCT ON снимает ровно одну. Тай-брейк обязателен и не
        // косметика: без него PostgreSQL волен взять любую, и название
        // товара менялось бы между обновлениями страницы само по себе.
        $listing = <<<'SQL'
            SELECT DISTINCT ON (marketplace_sku) marketplace_sku, name, offer_id
            FROM marketplace_listing
            WHERE company_id = :companyId
            ORDER BY marketplace_sku, first_seen_at ASC, marketplace_account_id ASC
            SQL;

        // Два уровня, а не один: маржа складывается из колонок, которые
        // сами появляются COALESCE-ами уровнем ниже, а PostgreSQL
        // не разрешает ссылаться на псевдоним в том же списке выборки.
        // Повторять три COALESCE ради одного уровня — верный способ
        // однажды поправить их в одном месте и забыть в другом.
        //
        // Маржа здесь нужна только для ORDER BY и курсора. Цифру для
        // клиента по-прежнему считает Money в BuildUnitEconomicsAction:
        // денежная арифметика живёт в типе, а не в базе. Совпадение
        // двух источников закреплено тестом.
        $joined = <<<SQL
            (
                SELECT j.*,
                       j.delivered_amount_minor + j.commission_amount_minor + j.expenses_total_minor AS margin_minor,
                       l.name,
                       l.offer_id
                FROM (
                    SELECT COALESCE(s.marketplace_sku, e.marketplace_sku) AS marketplace_sku,
                           COALESCE(s.currency, e.currency) AS currency,
                           COALESCE(s.delivered_quantity, 0) AS delivered_quantity,
                           COALESCE(s.delivered_amount_minor, 0) AS delivered_amount_minor,
                           COALESCE(s.commission_amount_minor, 0) AS commission_amount_minor,
                           COALESCE(s.ordered_quantity, 0) AS ordered_quantity,
                           COALESCE(e.expenses_total_minor, 0) AS expenses_total_minor,
                           COALESCE(s.cost_total_minor, 0) AS cost_total_minor,
                           COALESCE(s.quantity_without_cost, 0) AS quantity_without_cost,
                           s.cost_corrected_at
                    FROM ({$sales}) AS s
                    FULL OUTER JOIN ({$expenses}) AS e
                      ON s.marketplace_sku = e.marketplace_sku AND s.currency = e.currency
                ) AS j
                LEFT JOIN ({$listing}) AS l ON l.marketplace_sku = j.marketplace_sku
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
                'cost_total_minor',
                'quantity_without_cost',
                'cost_corrected_at',
                'margin_minor',
                'name',
                'offer_id',
            )
            ->from($joined)
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            // Порядок выбирает клиент; умолчание — выручка по убыванию,
            // ради неё экран и открывают. Артикул вторым столбцом
            // и всегда по возрастанию — чтобы порядок был устойчивым
            // при равных значениях, иначе курсор перескакивал бы строки.
            ->orderBy($sort->column(), $direction->sql())
            ->addOrderBy('marketplace_sku', 'ASC')
            // +1 — узнать, есть ли следующая страница, без COUNT(*)
            // на факт-таблице (§5).
            ->setMaxResults($limit + 1);

        if (null !== $cursor) {
            $qb->andWhere($cursor->after())
                ->setParameter('cursorValue', $cursor->sortValue)
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
            costTotalMinor: self::intValue($row['cost_total_minor']),
            quantityWithoutCost: self::intValue($row['quantity_without_cost']),
            costCorrectedAt: null === $row['cost_corrected_at'] ? null : self::stringValue($row['cost_corrected_at']),
            marginMinor: self::intValue($row['margin_minor']),
            name: null === $row['name'] ? null : self::stringValue($row['name']),
            offerId: null === $row['offer_id'] ? null : self::stringValue($row['offer_id']),
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
