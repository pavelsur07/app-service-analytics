<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use App\Shared\Domain\ValueObject\Money;

/**
 * Суммы Performance API в Money (ADR-035 п. 2).
 *
 * Валюта — константа коннектора, а не умолчание Money: поля валюты
 * в ответах Performance нет, рубль подтверждён заголовком CSV той же
 * выгрузки («Расход, ₽, с НДС») и сверкой с `by-day`, где валюта в каждой
 * сумме. Другая валюта кабинета — новый вопрос, а не ветка кода.
 *
 * Разделитель у методов разный, и шаблон строгий под каждый: асинхронный
 * отчёт и `statistics/expense` — запятая, `products/sku` — точка. Чужой
 * разделитель — отказ, а не догадка: «1.234» в запятом формате означало бы
 * тысячу, а не рубль.
 *
 * Без float (CLAUDE.md §3): целая и дробная части разбираются строкой.
 */
final class OzonPerformanceMoney
{
    public const string CURRENCY = 'RUB';

    public static function commaDecimal(string $value): Money
    {
        return self::parse($value, ',');
    }

    public static function dotDecimal(string $value): Money
    {
        return self::parse($value, '.');
    }

    /**
     * @param ','|'.' $separator
     */
    private static function parse(string $value, string $separator): Money
    {
        // Не больше 13 знаков целой части: сумма в минорных единицах
        // остаётся в int, и умножение не уходит в float при переполнении.
        if (1 !== preg_match('/\A\d{1,13}(?:'.preg_quote($separator, '/').'\d{1,2})?\z/', $value)) {
            throw new \UnexpectedValueException(\sprintf("Ozon Performance amount '%s' is not a non-negative decimal with '%s', at most 13 integer and two fraction digits.", $value, $separator));
        }

        [$whole, $fraction] = array_pad(explode($separator, $value, 2), 2, '');

        return Money::ofMinor((int) $whole * 100 + (int) str_pad($fraction, 2, '0'), self::CURRENCY);
    }
}
