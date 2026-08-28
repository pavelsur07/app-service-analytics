<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/**
 * Курсор страницы экрана себестоимости: пара «выручка, артикул».
 *
 * Отделён от UnitEconomicsCursor, когда юнит-экономика получила
 * пользовательскую сортировку. Общим он был по совпадению формы,
 * а не смысла: там сортировка выбирается клиентом из шести показателей,
 * здесь она одна и неизменна — выручка по убыванию. Общий класс
 * заставил бы этот экран нести перечисление чужих колонок, а имя
 * поля `deliveredAmountMinor` здесь и так было неправдой: в нём лежит
 * `revenue_minor`.
 *
 * Пара, а не одна выручка: у карточек без продаж она нулевая у всех,
 * и курсор по одному столбцу перескакивал бы строки. Подделать курсор
 * можно только в границах своей компании — companyId в запросе остаётся.
 */
final readonly class ListingCostsCursor
{
    public function __construct(
        public int $revenueMinor,
        public string $marketplaceSku,
    ) {
    }

    /**
     * Форма «выручка:артикул» — читаемая и без base64: сортировка идёт
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
        return $this->revenueMinor.':'.$this->marketplaceSku;
    }

    /**
     * Условие «строго после курсора» под сортировку
     * «$amountColumn DESC, marketplace_sku ASC».
     *
     * Кортежное `(выручка, артикул) < (:выручка, :артикул)` здесь
     * не годится: оно означает «выручка меньше ИЛИ равна и артикул
     * меньше», а при равной выручке порядок идёт по возрастанию
     * артикула, то есть нужен больше. Строки с бо́льшим артикулом
     * пропадали, с меньшим — повторялись на следующей странице.
     * Задевало не край, а самый частый случай: у карточек без продаж
     * выручка нулевая у всех, и равенство сумм там сплошное.
     */
    public function after(string $amountColumn): string
    {
        return \sprintf(
            '(%1$s < :cursorAmount OR (%1$s = :cursorAmount AND marketplace_sku > :cursorSku))',
            $amountColumn,
        );
    }
}
