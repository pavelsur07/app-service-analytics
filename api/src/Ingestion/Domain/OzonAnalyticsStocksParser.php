<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Разбор /v1/analytics/stocks в StockSnapshotFact (ADR-034). Domain: ни
 * HTTP, ни БД. Контракт снят с живого кабинета 2026-09-26
 * (docs/task/ozon-stocks-research.md).
 *
 * Ответ — {items: [...]} и больше ничего. Любой другой ключ верхнего
 * уровня (курсор, has_next, total) — признак, что площадка начала отдавать
 * ответ частями: тогда снимок из одной страницы был бы неполным, а
 * неполный снимок выдаёт «ноль» там, где остаток есть. Поэтому такой ответ
 * отвергается громко, а не разбирается.
 */
final class OzonAnalyticsStocksParser
{
    /** Сколько SKU площадка принимает в одном запросе (разведка: 100). */
    public const int BATCH_SIZE = 100;

    /**
     * Виды запаса, которые складываются в «прочее» — ради сверки итога
     * с кабинетом. Потоки (outbound_*, inbound_replenishment) — не запас.
     */
    private const array OTHER_KINDS = [
        'valid_stock_count',
        'waiting_docs_stock_count',
        'expiring_stock_count',
        'excess_stock_count',
        'other_stock_count',
        'waiting_docs_to_export_stock_count',
        'stock_not_being_sold',
    ];

    /**
     * @return list<StockSnapshotFact>
     */
    public function parse(
        string $rawBody,
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        \DateTimeImmutable $snapshotDate,
        Uuid $rawDocumentId,
    ): array {
        $decoded = json_decode($rawBody, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !isset($decoded['items']) || !\is_array($decoded['items'])) {
            throw new \UnexpectedValueException('Ozon /v1/analytics/stocks response must contain an "items" array.');
        }
        $extra = array_diff(array_keys($decoded), ['items']);
        if ([] !== $extra) {
            throw new \UnexpectedValueException('Ozon /v1/analytics/stocks returned unexpected top-level keys (possible pagination): '.implode(', ', $extra));
        }

        $facts = [];
        foreach ($decoded['items'] as $item) {
            if (!\is_array($item)) {
                throw new \UnexpectedValueException('Ozon stock item must be an object.');
            }
            $facts[] = new StockSnapshotFact(
                companyId: $companyId,
                marketplaceAccountId: $marketplaceAccountId,
                snapshotDate: $snapshotDate,
                marketplaceSku: (string) self::requireInt($item, 'sku'),
                warehouseId: self::requireInt($item, 'warehouse_id'),
                warehouseName: self::requireString($item, 'warehouse_name'),
                clusterId: self::requireInt($item, 'cluster_id'),
                clusterName: self::requireString($item, 'cluster_name'),
                quantities: new StockQuantities(
                    available: self::requireInt($item, 'available_stock_count'),
                    transit: self::requireInt($item, 'transit_stock_count'),
                    requested: self::requireInt($item, 'requested_stock_count'),
                    returnFromCustomer: self::requireInt($item, 'return_from_customer_stock_count'),
                    returnToSeller: self::requireInt($item, 'return_to_seller_stock_count'),
                    defect: self::requireInt($item, 'stock_defect_stock_count') + self::requireInt($item, 'transit_defect_stock_count'),
                    other: array_sum(array_map(static fn (string $kind): int => self::requireInt($item, $kind), self::OTHER_KINDS)),
                ),
                adsCluster: self::optionalRate($item, 'ads_cluster'),
                idcCluster: self::optionalInt($item, 'idc_cluster'),
                turnoverGradeCluster: self::optionalString($item, 'turnover_grade_cluster'),
                rawDocumentId: $rawDocumentId,
            );
        }

        return $facts;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function requireInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value)) {
            throw new \UnexpectedValueException("Expected field \"{$key}\" to be an integer.");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw new \UnexpectedValueException("Expected field \"{$key}\" to be a non-empty string.");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function optionalInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return \is_int($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Скорость продаж Ozon — JSON-число полной точности. Не деньги;
     * фиксируется строкой с четырьмя знаками для колонки numeric(12,4),
     * чтобы float не доходил до хранилища.
     *
     * @param array<array-key, mixed> $data
     */
    private static function optionalRate(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value) && !\is_float($value)) {
            return null;
        }

        return number_format((float) $value, 4, '.', '');
    }
}
