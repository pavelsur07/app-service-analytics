<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Карточки компании с выручкой за период и действующей себестоимостью —
 * то, из чего состоит экран ввода (ADR-013).
 *
 * **Порядок — по выручке, и это не украшение.** У клиента шестьдесят
 * карточек, и он не введёт шестьдесят цен. Он введёт пять–десять: те,
 * что кормят. Список по алфавиту или по дате появления заставил бы его
 * искать их глазами, а список по выручке ставит их сверху сам.
 *
 * Действующая цена — та, у которой `effective_from` не позже выбранной
 * даты и она самая поздняя из таких. Берётся LATERAL-подзапросом с
 * LIMIT 1: это ровно тот доступ, под который заведён уникальный индекс
 * себестоимости, — три первых столбца равенством, четвёртый диапазоном.
 *
 * Пагинация курсорная (§5): число карточек растёт с каталогом клиента.
 * Курсор — пара «выручка, артикул площадки»: по одной выручке порядок
 * неустойчив, у карточек без продаж она нулевая у всех.
 */
final readonly class ListingCostsQuery
{
    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 200;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function build(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $on,
        int $limit,
        ?UnitEconomicsCursor $cursor,
    ): QueryBuilder {
        // Выручка отдельным агрегатом, а не соединением по строкам:
        // у карточки их за период тысячи, и соединение до свёртки
        // размножило бы саму карточку (CLAUDE.md §5 — агрегации делает
        // PostgreSQL).
        $revenue = <<<'SQL'
            SELECT marketplace_sku,
                   COALESCE(SUM(amount_minor) FILTER (WHERE status = 'delivered'), 0) AS revenue_minor,
                   COALESCE(SUM(quantity) FILTER (WHERE status = 'delivered'), 0) AS delivered_quantity
            FROM sales_fact
            WHERE company_id = :companyId AND business_date >= :from AND business_date <= :to
            GROUP BY marketplace_sku
            SQL;

        $sql = <<<SQL
            (
                SELECT l.marketplace_sku,
                       l.marketplace_account_id,
                       l.offer_id,
                       l.name,
                       COALESCE(r.revenue_minor, 0) AS revenue_minor,
                       COALESCE(r.delivered_quantity, 0) AS delivered_quantity,
                       c.id AS cost_id,
                       c.unit_cost_minor,
                       c.currency AS cost_currency,
                       c.effective_from AS cost_effective_from,
                       c.version AS cost_version,
                       since.delivered_quantity AS delivered_since_cost
                FROM marketplace_listing AS l
                LEFT JOIN ({$revenue}) AS r ON r.marketplace_sku = l.marketplace_sku
                LEFT JOIN LATERAL (
                    SELECT id, unit_cost_minor, currency, effective_from, version
                    FROM marketplace_listing_cost AS lc
                    WHERE lc.company_id = l.company_id
                      AND lc.marketplace_account_id = l.marketplace_account_id
                      AND lc.marketplace_sku = l.marketplace_sku
                      AND lc.effective_from <= :on
                    ORDER BY lc.effective_from DESC
                    LIMIT 1
                ) AS c ON TRUE
                -- Сколько штук продано с даты, с которой действует цена.
                -- Нужно предупреждению перед исправлением (ADR-013):
                -- «затронет 12 дней и 47 проданных штук». Отдельный
                -- агрегат, потому что окно у него своё — от даты цены,
                -- а не от начала периода экрана.
                LEFT JOIN LATERAL (
                    SELECT COALESCE(SUM(quantity) FILTER (WHERE status = 'delivered'), 0) AS delivered_quantity
                    FROM sales_fact AS s
                    WHERE s.company_id = l.company_id
                      AND s.marketplace_sku = l.marketplace_sku
                      AND s.business_date >= c.effective_from
                      AND s.business_date <= :to
                ) AS since ON c.id IS NOT NULL
                WHERE l.company_id = :companyId
            ) AS listing
            SQL;

        $qb = $this->connection->createQueryBuilder()
            ->select(
                'marketplace_sku',
                'marketplace_account_id',
                'offer_id',
                'name',
                'revenue_minor',
                'delivered_quantity',
                'cost_id',
                'unit_cost_minor',
                'cost_currency',
                'cost_effective_from',
                'cost_version',
                'delivered_since_cost',
            )
            ->from($sql)
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('on', $on->format('Y-m-d'))
            ->orderBy('revenue_minor', 'DESC')
            ->addOrderBy('marketplace_sku', 'ASC')
            // +1 — узнать, есть ли следующая страница, без COUNT(*)
            // на факт-таблице (§5).
            ->setMaxResults($limit + 1);

        if (null !== $cursor) {
            $qb->andWhere($cursor->after('revenue_minor'))
                ->setParameter('cursorAmount', $cursor->deliveredAmountMinor)
                ->setParameter('cursorSku', $cursor->marketplaceSku);
        }

        return $qb;
    }

    /**
     * Сколько карточек компании ещё без себестоимости — число для строки
     * «задано у 8 из 62». Считается по каталогу, а не по странице:
     * доля, посчитанная по видимой части списка, вводила бы в заблуждение
     * ровно там, где список длинный.
     *
     * COUNT здесь по каталогу подключения, не по факт-таблице: запрет §5
     * написан про факты, которых миллионы, а карточек у продавца тысячи.
     */
    public function coverage(string $companyId, \DateTimeImmutable $on): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'COUNT(*) AS listings',
                'COUNT(c.id) AS priced',
            )
            ->from('marketplace_listing', 'l')
            ->leftJoin(
                'l',
                '(SELECT DISTINCT ON (company_id, marketplace_account_id, marketplace_sku)
                        company_id, marketplace_account_id, marketplace_sku, id
                  FROM marketplace_listing_cost
                  WHERE company_id = :companyId AND effective_from <= :on
                  ORDER BY company_id, marketplace_account_id, marketplace_sku, effective_from DESC)',
                'c',
                'c.company_id = l.company_id AND c.marketplace_account_id = l.marketplace_account_id AND c.marketplace_sku = l.marketplace_sku',
            )
            ->where('l.company_id = :companyId')
            ->setParameter('companyId', $companyId)
            ->setParameter('on', $on->format('Y-m-d'));
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): ListingCostRow
    {
        return new ListingCostRow(
            marketplaceSku: self::stringValue($row['marketplace_sku']),
            marketplaceAccountId: self::stringValue($row['marketplace_account_id']),
            offerId: self::nullableString($row['offer_id']),
            name: self::nullableString($row['name']),
            revenueMinor: self::intValue($row['revenue_minor']),
            deliveredQuantity: self::intValue($row['delivered_quantity']),
            costId: self::nullableString($row['cost_id']),
            unitCostMinor: null === $row['unit_cost_minor'] ? null : self::intValue($row['unit_cost_minor']),
            costCurrency: self::nullableString($row['cost_currency']),
            costEffectiveFrom: self::nullableString($row['cost_effective_from']),
            costVersion: null === $row['cost_version'] ? null : self::intValue($row['cost_version']),
            deliveredSinceCost: null === $row['delivered_since_cost'] ? null : self::intValue($row['delivered_since_cost']),
        );
    }

    private static function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string value in a listing cost row.');
        }

        return $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        return self::stringValue($value);
    }

    private static function intValue(mixed $value): int
    {
        // SUM и COUNT в PostgreSQL возвращают numeric/bigint, и DBAL
        // отдаёт их строкой: приводим явно, а не полагаемся на драйвер.
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && 1 === preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('Expected an integer value in a listing cost row.');
    }
}
