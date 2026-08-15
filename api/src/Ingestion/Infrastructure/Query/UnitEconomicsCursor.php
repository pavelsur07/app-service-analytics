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

    /**
     * Условие «строго после курсора» под сортировку
     * «$amountColumn DESC, marketplace_sku ASC».
     *
     * Здесь, а не копией в каждом запросе, потому что копия уже успела
     * разъехаться с сортировкой: кортежное `(сумма, артикул) < (:сумма,
     * :артикул)` означает «сумма меньше ИЛИ равна и артикул меньше», а
     * при равной сумме порядок идёт по возрастанию артикула, то есть
     * нужен больше. Строки с бо́льшим артикулом пропадали, с меньшим —
     * повторялись на следующей странице. Задевало не край, а самый
     * частый случай: у карточек без продаж выручка нулевая у всех,
     * и равенство сумм там сплошное.
     */
    public function after(string $amountColumn): string
    {
        return \sprintf(
            '(%1$s < :cursorAmount OR (%1$s = :cursorAmount AND marketplace_sku > :cursorSku))',
            $amountColumn,
        );
    }
}
