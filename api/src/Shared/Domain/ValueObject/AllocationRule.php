<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

/**
 * Правило распределения остатка при аллокации Money. Значения по умолчанию
 * нет — вызывающий указывает правило явно при каждом вызове Money::allocate().
 */
enum AllocationRule
{
    /**
     * Остаток раздаётся по одной минорной единице первым получателям
     * по порядку. 100 на троих поровну → 34/33/33.
     */
    case RemainderToFirst;
}
