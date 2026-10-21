<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Список отправлений из тела выгрузки продаж FBO — в обоих форматах,
 * которые лежат в raw под одним типом отчёта (ADR-042 п. 2):
 * `result` — `/v2/posting/fbo/list` (отключён Ozon 2026-10-19),
 * `postings` — `/v3/posting/fbo/list`. Raw неизменяем (ADR-006),
 * и разбор старых тел нужен backfill статусов и истории выкупа.
 *
 * **Денежные поля приходят в разбор строками.** Ozon отдаёт цену
 * и комиссию JSON-числом (`"commission_amount":-1152.96`), а `json_decode`
 * превращает дробное число во float раньше любого нашего кода — флага,
 * который это отключает, у PHP нет. Поэтому до разбора числовые значения
 * ключей из `MONEY_KEYS` берутся в кавычки, и `Money::ofDecimalString`
 * получает ровно те цифры, что прислала площадка (CLAUDE.md §3).
 * Меняется копия для разбора; raw хранит ответ байт в байт (ADR-006).
 * Значение-строку подмена не задевает: внутри строки JSON кавычки
 * экранированы, и `"price":` там не встречается.
 */
final class OzonPostingList
{
    /** Цена товара и комиссия: v2 — `price`, `commission_amount`; v3 — ещё `commission.amount`. */
    private const string MONEY_KEYS = 'price|commission_amount|amount';

    private function __construct()
    {
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public static function postings(string $rawBody): array
    {
        $decoded = json_decode(self::moneyAsStrings($rawBody), true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException('Ozon posting list response must be an object.');
        }

        $list = $decoded['postings'] ?? $decoded['result'] ?? null;
        if (!\is_array($list) || !array_is_list($list)) {
            throw new \UnexpectedValueException('Ozon posting list response must contain a "postings" (v3) or "result" (v2) array.');
        }

        $postings = [];
        foreach ($list as $posting) {
            if (!\is_array($posting)) {
                throw new \UnexpectedValueException('Ozon posting entry must be an object.');
            }
            $postings[] = $posting;
        }

        return $postings;
    }

    /**
     * Любое JSON-число денежного ключа целиком, включая экспоненту:
     * оставленное числом, оно стало бы float ещё в `json_decode`.
     * Строку «1e3» затем громко отвергает `Money::ofDecimalString`.
     */
    private static function moneyAsStrings(string $rawBody): string
    {
        $quoted = preg_replace('/("(?:'.self::MONEY_KEYS.')"\s*:\s*)(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)(?=\s*[,}\]])/', '$1"$2"', $rawBody);
        if (null === $quoted) {
            throw new \UnexpectedValueException('Ozon posting list response could not be prepared for decimal parsing.');
        }

        return $quoted;
    }
}
