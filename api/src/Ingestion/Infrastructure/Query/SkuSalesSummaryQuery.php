<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Итог продаж по одному артикулу за окно дней — то, что расширение
 * показывает поверх карточки товара.
 *
 * Считает PostgreSQL, не PHP (CLAUDE.md §5): наружу уходит несколько
 * чисел, а не выборка фактов.
 *
 * Группировка по валюте — не задел на будущее, а требование §3: суммы
 * разных валют не складываются. Сегодня строка будет одна (Ozon, RUB),
 * но группировка не даёт молча сложить их, если валюта когда-нибудь
 * окажется второй.
 *
 * Три категории, а не две: ADR-009 требует от витрины явного счёта
 * «заказано / доставлено / отменено» по колонке статуса. Свернуть
 * доставленное с ожидающим отгрузки — значит показать как продажу то,
 * что ещё может отмениться. FILTER, а не CASE — то же самое, но читается
 * как условие агрегата, чем оно и является.
 */
final readonly class SkuSalesSummaryQuery
{
    /**
     * Часовой пояс площадки (ADR-009): бизнес-дата в нём и записана,
     * значит и граница окна обязана считаться в нём. Константа коннектора
     * Ozon, не настройка подключения — как в OzonPostingFboListParser
     * и DispatchActiveOzonSyncsAction.
     */
    private const string TIMEZONE = 'Europe/Moscow';

    /**
     * Валют у одного артикула структурно единицы, но список без лимита
     * не отдаётся никогда, даже внутренний (CLAUDE.md §5) — потолок здесь
     * защитный, как в UserCompaniesQuery, а не раскрытая наружу страница.
     */
    private const int MAX_CURRENCIES = 200;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function build(string $companyId, string $marketplaceSku, int $days): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'currency',
                "COALESCE(SUM(quantity) FILTER (WHERE status <> 'cancelled'), 0) AS ordered_quantity",
                "COALESCE(SUM(amount_minor) FILTER (WHERE status <> 'cancelled'), 0) AS ordered_amount_minor",
                "COALESCE(SUM(quantity) FILTER (WHERE status = 'delivered'), 0) AS delivered_quantity",
                "COALESCE(SUM(amount_minor) FILTER (WHERE status = 'delivered'), 0) AS delivered_amount_minor",
                "COALESCE(SUM(quantity) FILTER (WHERE status = 'cancelled'), 0) AS cancelled_quantity",
                "COALESCE(SUM(amount_minor) FILTER (WHERE status = 'cancelled'), 0) AS cancelled_amount_minor",
            )
            ->from('sales_fact')
            ->where('company_id = :companyId')
            ->andWhere('marketplace_sku = :marketplaceSku')
            // Граница считается здесь, а не через CURRENT_DATE в SQL:
            // CURRENT_DATE берёт часовой пояс сессии PostgreSQL, и рядом
            // с полуночью окно съезжало бы на сутки относительно
            // бизнес-даты, записанной по календарю площадки.
            ->andWhere('business_date >= :since')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplaceSku', $marketplaceSku)
            ->setParameter('since', self::windowStart($days))
            ->groupBy('currency')
            ->orderBy('currency', 'ASC')
            ->setMaxResults(self::MAX_CURRENCIES);
    }

    private static function windowStart(int $days): string
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));

        return $today->modify(\sprintf('-%d days', $days - 1))->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): SkuSalesSummaryRow
    {
        return new SkuSalesSummaryRow(
            currency: self::stringValue($row['currency']),
            orderedQuantity: self::intValue($row['ordered_quantity']),
            orderedAmountMinor: self::intValue($row['ordered_amount_minor']),
            deliveredQuantity: self::intValue($row['delivered_quantity']),
            deliveredAmountMinor: self::intValue($row['delivered_amount_minor']),
            cancelledQuantity: self::intValue($row['cancelled_quantity']),
            cancelledAmountMinor: self::intValue($row['cancelled_amount_minor']),
        );
    }

    private static function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string value in a sales summary row.');
        }

        return $value;
    }

    private static function intValue(mixed $value): int
    {
        // SUM в PostgreSQL возвращает numeric — DBAL отдаёт его строкой,
        // не int. Приведение здесь, а не в контроллере: денежная величина
        // обязана дойти до DTO целым числом минорных единиц (CLAUDE.md §3),
        // float в этой цепочке не появляется нигде.
        if (!\is_int($value) && !\is_string($value)) {
            throw new \UnexpectedValueException('Expected an int-like value in a sales summary row.');
        }

        return (int) $value;
    }
}
