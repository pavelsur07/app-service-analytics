<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Request;

use App\SalesPlanning\Application\SkuPeriodInput;
use App\SalesPlanning\Domain\SalesPlanLine;
use App\SalesPlanning\Domain\SkuPeriodPlan;
use Symfony\Component\Uid\Uuid;

/**
 * Разбор тел запросов плана продаж, не Symfony Form (patterns.md,
 * «HTTP-слой»). Ошибка — InvalidArgumentException с кодом для 422.
 *
 * Цена — целое в минорных единицах: JSON-число с дробью — double,
 * и `1299.90` приезжает как 1299.8999… ADR-004 запрещает float
 * в деньгах, и граница, где это проще всего нарушить, — здесь.
 */
final class SalesPlanRequest
{
    /** @return array{version: int, metric: \App\SalesPlanning\Domain\SalesPlanMetric} */
    public static function metricFromJson(string $body): array
    {
        $decoded = self::decode($body);
        $version = self::approvalVersionFromJson($body);
        $raw = $decoded['metric'] ?? null;
        $metric = \is_string($raw) ? \App\SalesPlanning\Domain\SalesPlanMetric::tryFrom($raw) : null;
        if (null === $metric) {
            throw new \InvalidArgumentException('sales_plan_metric_invalid');
        }

        return ['version' => $version, 'metric' => $metric];
    }

    public static function approvalVersionFromJson(string $body): int
    {
        $decoded = self::decode($body);
        $version = $decoded['version'] ?? null;
        if (!\is_int($version) || $version < 1) {
            throw new \InvalidArgumentException('version_required');
        }

        return $version;
    }

    /**
     * @return array{marketplaceAccountId: string, name: string, dateFrom: \DateTimeImmutable, dateTo: \DateTimeImmutable, currency: string}
     *
     * @throws \InvalidArgumentException
     */
    public static function creationFromJson(string $body): array
    {
        $decoded = self::decode($body);

        $accountId = $decoded['marketplaceAccountId'] ?? null;
        if (!\is_string($accountId) || !Uuid::isValid(trim($accountId))) {
            throw new \InvalidArgumentException('marketplace_account_id_required');
        }

        $name = $decoded['name'] ?? null;
        if (!\is_string($name)) {
            throw new \InvalidArgumentException('name_required');
        }

        $currency = $decoded['currency'] ?? null;
        if (!\is_string($currency) || 1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('currency_required');
        }

        return [
            'marketplaceAccountId' => trim($accountId),
            'name' => $name,
            'dateFrom' => self::date($decoded, 'dateFrom', 'date_from_invalid'),
            'dateTo' => self::date($decoded, 'dateTo', 'date_to_invalid'),
            'currency' => $currency,
        ];
    }

    /**
     * @return array{version: int, items: list<SkuPeriodInput>}
     *
     * @throws \InvalidArgumentException
     */
    public static function skusFromJson(string $body): array
    {
        $decoded = self::decode($body);

        // Версия обязательна (ADR-008): изменение «без версии» как
        // безусловное правила прямо запрещают.
        $version = $decoded['version'] ?? null;
        if (!\is_int($version)) {
            throw new \InvalidArgumentException('version_required');
        }

        $rawItems = $decoded['items'] ?? null;
        if (!\is_array($rawItems) || !array_is_list($rawItems) || [] === $rawItems) {
            throw new \InvalidArgumentException('items_required');
        }

        $items = [];
        foreach ($rawItems as $raw) {
            if (!\is_array($raw)) {
                throw new \InvalidArgumentException('item_invalid');
            }

            $sku = $raw['marketplaceSku'] ?? null;
            if (!\is_string($sku) || '' === trim($sku) || \strlen(trim($sku)) > 64) {
                throw new \InvalidArgumentException('marketplace_sku_required');
            }

            $quantity = $raw['totalQuantity'] ?? null;
            if (!\is_int($quantity) || $quantity < 0 || $quantity > SkuPeriodPlan::MaxTotalQuantity) {
                throw new \InvalidArgumentException('total_quantity_invalid');
            }

            $price = $raw['priceMinor'] ?? null;
            if (!\is_int($price) || $price <= 0 || $price > SalesPlanLine::MaxPriceMinor) {
                throw new \InvalidArgumentException('price_minor_invalid');
            }

            $items[] = new SkuPeriodInput(trim($sku), $quantity, $price);
        }

        return ['version' => $version, 'items' => $items];
    }

    /**
     * Правка одного дня: SKU, дата, штуки, цена и версия плана.
     *
     * Цена необязательна: без неё у дня остаётся прежняя — правка штук
     * не должна заставлять вводить цену заново.
     *
     * @return array{version: int, marketplaceSku: string, date: \DateTimeImmutable, quantity: int, priceMinor: int|null}
     *
     * @throws \InvalidArgumentException
     */
    public static function dayFromJson(string $body): array
    {
        $decoded = self::decode($body);

        $version = $decoded['version'] ?? null;
        if (!\is_int($version)) {
            throw new \InvalidArgumentException('version_required');
        }

        $sku = $decoded['marketplaceSku'] ?? null;
        if (!\is_string($sku) || '' === trim($sku) || \strlen(trim($sku)) > 64) {
            throw new \InvalidArgumentException('marketplace_sku_required');
        }

        $quantity = $decoded['quantity'] ?? null;
        if (!\is_int($quantity) || $quantity < 0 || $quantity > SkuPeriodPlan::MaxTotalQuantity) {
            throw new \InvalidArgumentException('quantity_invalid');
        }

        $price = $decoded['priceMinor'] ?? null;
        if (null !== $price && (!\is_int($price) || $price <= 0 || $price > SalesPlanLine::MaxPriceMinor)) {
            throw new \InvalidArgumentException('price_minor_invalid');
        }

        return [
            'version' => $version,
            'marketplaceSku' => trim($sku),
            'date' => self::date($decoded, 'date', 'date_invalid'),
            'quantity' => $quantity,
            'priceMinor' => $price,
        ];
    }

    /**
     * @param array<array-key, mixed> $decoded
     */
    private static function date(array $decoded, string $field, string $code): \DateTimeImmutable
    {
        $raw = $decoded[$field] ?? null;
        if (!\is_string($raw)) {
            throw new \InvalidArgumentException($code);
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new \DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d') !== $raw) {
            // Вторая проверка ловит 2026-02-30: такая дата разбирается
            // и молча переезжает на 2 марта.
            throw new \InvalidArgumentException($code);
        }

        return $date;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('malformed_json');
        }

        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('malformed_json');
        }

        return $decoded;
    }
}
