<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/**
 * По какому показателю упорядочена страница юнит-экономики.
 *
 * Перечисление, а не строка из запроса: имя колонки попадает в SQL
 * подстановкой, и белый список здесь — единственное, что отделяет
 * сортировку от инъекции. Значение из HTTP становится этим типом
 * на границе (контроллер) или не становится вовсе — тогда 422.
 *
 * Сортируются только числовые показатели: все шесть колонок целые
 * (минорные единицы и штуки) и все обёрнуты COALESCE в подзапросе,
 * поэтому NULL в порядке не участвует и NULLS FIRST/LAST не нужен.
 */
enum UnitEconomicsSort: string
{
    case Delivered = 'delivered';
    case Revenue = 'revenue';
    case Commission = 'commission';
    case Expenses = 'expenses';
    case Cost = 'cost';
    case Margin = 'margin';

    /**
     * Колонка объединённого подзапроса, а не таблицы: сортировка идёт
     * по агрегату за окно, который строится на лету. Индекса под такой
     * порядок не существует и заводить его незачем — стоимость запроса
     * определяется агрегацией, а не сортировкой, и от выбранной колонки
     * не зависит.
     */
    public function column(): string
    {
        return match ($this) {
            self::Delivered => 'delivered_quantity',
            self::Revenue => 'delivered_amount_minor',
            self::Commission => 'commission_amount_minor',
            self::Expenses => 'expenses_total_minor',
            self::Cost => 'cost_total_minor',
            self::Margin => 'margin_minor',
        };
    }

    /**
     * Значение этой колонки в строке — то, что уходит в курсор
     * следующей страницы.
     *
     * Рядом с column() намеренно: две карты по одному перечислению,
     * которые обязаны совпадать. Новый вариант сортировки не пройдёт
     * PHPStan, пока не описан в обеих.
     */
    public function valueOf(UnitEconomicsSkuRow $row): int
    {
        return match ($this) {
            self::Delivered => $row->deliveredQuantity,
            self::Revenue => $row->deliveredAmountMinor,
            self::Commission => $row->commissionAmountMinor,
            self::Expenses => $row->expensesTotalMinor,
            self::Cost => $row->costTotalMinor,
            self::Margin => $row->marginMinor,
        };
    }
}
