<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/**
 * Направление сортировки страницы юнит-экономики.
 *
 * Отдельный тип по той же причине, что и UnitEconomicsSort: значение
 * уходит в SQL подстановкой.
 */
enum UnitEconomicsDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';

    public function sql(): string
    {
        return self::Asc === $this ? 'ASC' : 'DESC';
    }

    /**
     * Оператор «строго дальше по этому порядку» для keyset-курсора:
     * при убывании следующая строка меньше текущей, при возрастании —
     * больше. Тай-брейк по артикулу этим оператором не пользуется:
     * артикул сортируется по возрастанию при любом направлении.
     */
    public function beyond(): string
    {
        return self::Asc === $this ? '>' : '<';
    }
}
