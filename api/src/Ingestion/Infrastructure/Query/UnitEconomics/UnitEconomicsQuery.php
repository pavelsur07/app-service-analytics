<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\UnitEconomics;

use App\Ingestion\Domain\OzonFeeTypeNames;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Юнит-экономика за период по методу начисления (ADR-036): по товару —
 * выручка и комиссия нетто возвратов, расходы площадки и реклама;
 * отдельно — расходы кабинета, к товару не привязанные. Всё — из ленты
 * начислений `by-day` (`marketplace_expense_fact`) по дате начисления;
 * `sales_fact` здесь не читается.
 *
 * Считает PostgreSQL, не PHP (CLAUDE.md §5): наружу уходят агрегаты,
 * а не выборка фактов.
 *
 * Товары берутся объединением продаж и расходов — FULL OUTER JOIN
 * по артикулу, а не по дате. Обе стороны свёрнуты за один и тот же
 * период начислений и склеиваются по товару. Строки продажи (выручка
 * и комиссия, `OzonFeeTypeNames::SALE_TYPES`) из расходов исключаются:
 * это одна таблица, и без фильтра выручка попала бы в издержки.
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
     * Страница товаров: выручка и комиссия из строк продажи, итог
     * расходов — из остальных строк ленты, обе стороны за один период.
     *
     * $cursor — пара из предыдущей страницы; null для первой.
     */
    public function skus(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit,
        int $days,
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
        if (null !== $cursor && !$cursor->matches($sort, $direction, $days)) {
            throw new \InvalidArgumentException('Cursor was issued for a different sort order.');
        }

        // Продажи — из ленты начислений `by-day`, по дате начисления
        // (ADR-036), а не из отправлений по дате заказа: юнит-экономика
        // — это деньги, и отчёт, с которым её сверяют, — начисления
        // площадки. Штука — строка выручки: `+` продажа, `−` возврат.
        // Выручка и комиссия нетто — возвраты уже со своим знаком.
        //
        // Себестоимость соединяется с КАЖДОЙ строкой выручки по дате,
        // а не берётся одна на период (ADR-013). У продажи — дата её
        // начисления. У возврата — дата исходной продажи той же пары
        // «отправление + SKU»: иначе смена цены между продажей и
        // возвратом дала бы прибыль или убыток, которых не было. Продажа
        // раньше загруженной истории — по дате самого возврата.
        //
        // Умножать нечего: штука одна на строку. Знак себестоимости
        // отрицательный намеренно — как у комиссии и расходов площадки;
        // у возврата — положительный, это сторно. CASE, а не SIGN():
        // SIGN над bigint PostgreSQL вправе посчитать в double precision.
        $sales = <<<'SQL'
            SELECT f.marketplace_sku,
                   f.currency,
                   COUNT(*) FILTER (WHERE f.fee_type_id = :revenueType AND f.amount_minor > 0) AS delivered_quantity,
                   COUNT(*) FILTER (WHERE f.fee_type_id = :revenueType AND f.amount_minor < 0) AS returned_quantity,
                   COALESCE(SUM(f.amount_minor) FILTER (WHERE f.fee_type_id = :revenueType), 0) AS delivered_amount_minor,
                   COALESCE(SUM(f.amount_minor) FILTER (WHERE f.fee_type_id = :revenueType AND f.amount_minor < 0), 0) AS returns_amount_minor,
                   COALESCE(SUM(f.amount_minor) FILTER (WHERE f.fee_type_id = :saleCommissionType), 0) AS commission_amount_minor,
                   COALESCE(SUM(CASE WHEN f.amount_minor > 0 THEN -c.unit_cost_minor ELSE c.unit_cost_minor END)
                       FILTER (WHERE f.fee_type_id = :revenueType), 0) AS cost_total_minor,
                   COUNT(*) FILTER (WHERE f.fee_type_id = :revenueType AND c.unit_cost_minor IS NULL) AS quantity_without_cost,
                   MAX(c.updated_at) FILTER (WHERE f.fee_type_id = :revenueType AND c.updated_at > c.recorded_at) AS cost_corrected_at
            FROM marketplace_expense_fact AS f
            LEFT JOIN LATERAL (
                SELECT MIN(s.business_date) AS sale_date
                FROM marketplace_expense_fact AS s
                WHERE f.fee_type_id = :revenueType
                  AND f.amount_minor < 0
                  AND f.unit_number <> ''
                  AND s.company_id = f.company_id
                  AND s.marketplace_account_id = f.marketplace_account_id
                  AND s.marketplace_sku = f.marketplace_sku
                  AND s.unit_number = f.unit_number
                  AND s.fee_type_id = :revenueType
                  AND s.amount_minor > 0
            ) AS o ON TRUE
            LEFT JOIN LATERAL (
                SELECT lc.unit_cost_minor, lc.updated_at, lc.recorded_at
                FROM marketplace_listing_cost AS lc
                WHERE f.fee_type_id = :revenueType
                  AND lc.company_id = f.company_id
                  AND lc.marketplace_account_id = f.marketplace_account_id
                  AND lc.marketplace_sku = f.marketplace_sku
                  AND lc.effective_from <= COALESCE(o.sale_date, f.business_date)
                ORDER BY lc.effective_from DESC
                LIMIT 1
            ) AS c ON TRUE
            WHERE f.company_id = :companyId AND f.business_date >= :from AND f.business_date <= :to
              AND f.fee_type_id IN (:revenueType, :saleCommissionType)
            GROUP BY f.marketplace_sku, f.currency
            SQL;

        $expenses = <<<'SQL'
            SELECT marketplace_sku,
                   currency,
                   SUM(amount_minor) AS expenses_total_minor
            FROM marketplace_expense_fact
            WHERE company_id = :companyId AND business_date >= :from AND business_date <= :to
              AND marketplace_sku <> ''
              AND fee_type_id NOT IN (:saleTypes)
            GROUP BY marketplace_sku, currency
            SQL;

        // Реклама по SKU — из SKU-отчётов площадки как есть (ADR-035),
        // по дню расхода, всеми кампаниями товара. Третья сторона того же
        // объединения: товар с рекламой, но без продаж и расходов, в
        // список попадает, а не проходит мимо лимита страницы.
        $advertising = <<<'SQL'
            SELECT marketplace_sku,
                   currency,
                   SUM(amount_minor) AS advertising_total_minor
            FROM ad_sku_expense_fact
            WHERE company_id = :companyId AND business_date >= :from AND business_date <= :to
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
            SELECT DISTINCT ON (marketplace_sku) marketplace_sku, name, offer_id, photo_url
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
                       j.delivered_amount_minor + j.commission_amount_minor + j.expenses_total_minor
                           + j.advertising_total_minor AS margin_minor,
                       l.name,
                       l.offer_id,
                       l.photo_url
                FROM (
                    SELECT COALESCE(s.marketplace_sku, e.marketplace_sku, a.marketplace_sku) AS marketplace_sku,
                           COALESCE(s.currency, e.currency, a.currency) AS currency,
                           COALESCE(s.delivered_quantity, 0) AS delivered_quantity,
                           COALESCE(s.returned_quantity, 0) AS returned_quantity,
                           COALESCE(s.delivered_amount_minor, 0) AS delivered_amount_minor,
                           COALESCE(s.returns_amount_minor, 0) AS returns_amount_minor,
                           COALESCE(s.commission_amount_minor, 0) AS commission_amount_minor,
                           COALESCE(e.expenses_total_minor, 0) AS expenses_total_minor,
                           COALESCE(a.advertising_total_minor, 0) AS advertising_total_minor,
                           COALESCE(s.cost_total_minor, 0) AS cost_total_minor,
                           COALESCE(s.quantity_without_cost, 0) AS quantity_without_cost,
                           s.cost_corrected_at
                    FROM ({$sales}) AS s
                    FULL OUTER JOIN ({$expenses}) AS e
                      ON s.marketplace_sku = e.marketplace_sku AND s.currency = e.currency
                    FULL OUTER JOIN ({$advertising}) AS a
                      ON a.marketplace_sku = COALESCE(s.marketplace_sku, e.marketplace_sku)
                     AND a.currency = COALESCE(s.currency, e.currency)
                ) AS j
                LEFT JOIN ({$listing}) AS l ON l.marketplace_sku = j.marketplace_sku
            ) AS sku
            SQL;

        $qb = $this->connection->createQueryBuilder()
            ->select(
                'marketplace_sku',
                'currency',
                'delivered_quantity',
                'returned_quantity',
                'delivered_amount_minor',
                'returns_amount_minor',
                'commission_amount_minor',
                'expenses_total_minor',
                'advertising_total_minor',
                'cost_total_minor',
                'quantity_without_cost',
                'cost_corrected_at',
                'margin_minor',
                'name',
                'offer_id',
                'photo_url',
            )
            ->from($joined)
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('revenueType', OzonFeeTypeNames::REVENUE)
            ->setParameter('saleCommissionType', OzonFeeTypeNames::SALE_COMMISSION)
            ->setParameter('saleTypes', OzonFeeTypeNames::SALE_TYPES, ArrayParameterType::INTEGER)
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
            // Выручка и комиссия — строки продажи, не расхода (ADR-036 п. 7).
            ->andWhere('fee_type_id NOT IN (:saleTypes)')
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('skus', json_encode($marketplaceSkus, \JSON_THROW_ON_ERROR))
            ->setParameter('saleTypes', OzonFeeTypeNames::SALE_TYPES, ArrayParameterType::INTEGER)
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
            // «Оплата за клик» идёт отдельно — остатком, не разнесённым
            // по товарам (advertisingTotals, ADR-035 п. 6).
            ->andWhere('fee_type_id <> :payPerClick')
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('payPerClick', OzonFeeTypeNames::PAY_PER_CLICK)
            ->groupBy('fee_type_id')
            ->addGroupBy('currency')
            // Типов начислений у площадки 119 — потолок с запасом
            // и всё равно ограничен (§5).
            ->setMaxResults(self::MAX_LIMIT);
    }

    /**
     * Реклама за период по валютам: итог «Оплаты за клик» из `by-day`
     * и итог рекламы по SKU из SKU-отчётов (ADR-035 п. 6). Разницу —
     * остаток, не разнесённый по товарам, — считает Money в сценарии.
     *
     * Строк столько, сколько валют у компании за период, — одна;
     * потолок защитный.
     */
    public function advertisingTotals(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        $sides = <<<'SQL'
            (
                SELECT currency, amount_minor AS by_day_minor, 0 AS sku_minor
                FROM marketplace_expense_fact
                WHERE company_id = :companyId AND business_date >= :from AND business_date <= :to
                  AND marketplace_sku = '' AND fee_type_id = :payPerClick
                UNION ALL
                SELECT currency, 0, amount_minor
                FROM ad_sku_expense_fact
                WHERE company_id = :companyId AND business_date >= :from AND business_date <= :to
            ) AS sides
            SQL;

        return $this->connection->createQueryBuilder()
            ->select('currency', 'SUM(by_day_minor) AS by_day_minor', 'SUM(sku_minor) AS sku_minor')
            ->from($sides)
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('payPerClick', OzonFeeTypeNames::PAY_PER_CLICK)
            ->groupBy('currency')
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
            returnedQuantity: self::intValue($row['returned_quantity']),
            deliveredAmountMinor: self::intValue($row['delivered_amount_minor']),
            returnsAmountMinor: self::intValue($row['returns_amount_minor']),
            commissionAmountMinor: self::intValue($row['commission_amount_minor']),
            expensesTotalMinor: self::intValue($row['expenses_total_minor']),
            advertisingTotalMinor: self::intValue($row['advertising_total_minor']),
            costTotalMinor: self::intValue($row['cost_total_minor']),
            quantityWithoutCost: self::intValue($row['quantity_without_cost']),
            costCorrectedAt: null === $row['cost_corrected_at'] ? null : self::stringValue($row['cost_corrected_at']),
            marginMinor: self::intValue($row['margin_minor']),
            name: null === $row['name'] ? null : self::stringValue($row['name']),
            offerId: null === $row['offer_id'] ? null : self::stringValue($row['offer_id']),
            photoUrl: null === $row['photo_url'] ? null : self::stringValue($row['photo_url']),
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
