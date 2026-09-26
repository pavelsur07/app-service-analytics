<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Localization;

/**
 * Показатели группы строк отчёта «Локализация» (LocalizationSql::metricsSelect).
 * Доли — basis points, деньги — минорные единицы в $currency. Ниже
 * LocalizationSql::MIN_QUANTITY ни одна доля и ни одна логистика на штуку
 * не отдаются:
 * при трёх продажах они шумят, а экран выдал бы шум за вывод.
 */
final readonly class LocalizationMetrics
{
    public function __construct(
        public int $quantity,
        public int $clusteredQuantity,
        public int $localQuantity,
        public int $nonlocalQuantity,
        public ?int $localShareBps,
        public int $chargedQuantity,
        public ?int $chargedShareBps,
        public ?int $localForwardCostPerUnitMinor,
        public ?int $nonlocalForwardCostPerUnitMinor,
        public ?int $reverseCostMinor,
        public ?string $currency,
        public bool $sufficientData,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $clustered = self::int($row['clustered_quantity']);
        $localCharged = self::int($row['local_charged_quantity']);
        $nonlocalCharged = self::int($row['nonlocal_charged_quantity']);
        $sufficient = $clustered >= LocalizationSql::MIN_QUANTITY;

        // Разные валюты в одной группе не складываются и не сравниваются
        // (CLAUDE.md §3): молчаливый выбор одной из них был бы ошибкой.
        $currencyMin = self::nullableString($row['currency_min']);
        if ($currencyMin !== self::nullableString($row['currency_max'])) {
            throw new \UnexpectedValueException('Localization logistics mix currencies within one group.');
        }

        return new self(
            quantity: self::int($row['quantity']),
            clusteredQuantity: $clustered,
            localQuantity: self::int($row['local_quantity']),
            nonlocalQuantity: self::int($row['nonlocal_quantity']),
            localShareBps: $sufficient ? self::nullableInt($row['local_share_bps']) : null,
            chargedQuantity: self::int($row['charged_quantity']),
            chargedShareBps: $sufficient ? self::nullableInt($row['charged_share_bps']) : null,
            localForwardCostPerUnitMinor: $localCharged >= LocalizationSql::MIN_QUANTITY
                ? self::nullableInt($row['local_forward_cost_per_unit_minor'])
                : null,
            nonlocalForwardCostPerUnitMinor: $nonlocalCharged >= LocalizationSql::MIN_QUANTITY
                ? self::nullableInt($row['nonlocal_forward_cost_per_unit_minor'])
                : null,
            reverseCostMinor: self::nullableInt($row['reverse_cost_minor']),
            currency: $currencyMin,
            sufficientData: $sufficient,
        );
    }

    public static function int(mixed $value): int
    {
        if (!\is_int($value) && !(\is_string($value) && is_numeric($value))) {
            throw new \UnexpectedValueException('Expected an integer in a localization row.');
        }

        return (int) $value;
    }

    public static function nullableInt(mixed $value): ?int
    {
        return null === $value ? null : self::int($value);
    }

    public static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string in a localization row.');
        }

        return $value;
    }

    public static function nullableString(mixed $value): ?string
    {
        return null === $value ? null : self::string($value);
    }
}
