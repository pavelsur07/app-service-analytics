<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/**
 * Курсор страницы отчёта: пара «выручка, артикул».
 *
 * Пара, а не одна выручка: у товаров без продаж она нулевая у всех,
 * и курсор по одному столбцу перескакивал бы строки. Подделать курсор
 * можно только в границах своей компании — companyId в запросе остаётся.
 */
final readonly class UnitEconomicsCursor
{
    public function __construct(
        public int $deliveredAmountMinor,
        public string $marketplaceSku,
    ) {
    }

    /**
     * Форма «сумма:артикул» — читаемая и без base64: сортировка идёт
     * по двум столбцам, кодировать нечего (тот же приём, что
     * у CompanySkusQuery).
     */
    public static function fromString(string $raw): ?self
    {
        $parts = explode(':', $raw, 2);
        if (2 !== \count($parts) || 1 !== preg_match('/^-?\d+$/', $parts[0])) {
            return null;
        }

        return new self((int) $parts[0], $parts[1]);
    }

    public function toString(): string
    {
        return $this->deliveredAmountMinor.':'.$this->marketplaceSku;
    }
}
