<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use App\Shared\Domain\ValueObject\Money;

/**
 * Цены карточек из `/v3/product/info/list` (ADR-015). Domain,
 * не Infrastructure: ни одного вызова наружу. Проверяется на той же
 * снятой с боевого кабинета фикстуре, что и разбор наименований
 * (CLAUDE.md §9).
 *
 * Отдельный класс, а не второй метод `OzonProductInfoListParser`:
 * тот отдаёт «sku → наименование» и попадает в `marketplace_listing`,
 * этот — историю цен в свою таблицу. Общий у них только источник,
 * а не судьба: наименование перезаписывается, цена накапливается.
 *
 * **Суммы приходят строками вида «3300.00», и это удача.** У продаж
 * Ozon отдаёт их JSON-числом, и `OzonPostingFboListParser` вынужден
 * сначала зафиксировать float через `number_format`. Здесь фиксировать
 * нечего — строка разбирается целочисленно как есть, и копейки
 * не размываются в принципе (ADR-004).
 *
 * **Отсутствие цены и неожиданный формат — разные вещи.** Товар без
 * цены пропускается: он заведён, но не выставлен, и это не повод
 * потерять остальные шестьдесят. А `price`, приехавший числом вместо
 * строки или с непонятной точностью, роняет разбор исключением —
 * это дрейф схемы площадки, и тихо записать ноль цен означало бы
 * оставить прежние значения действующими навсегда, ни разу
 * не пожаловавшись.
 */
final class OzonListingPriceParser
{
    /**
     * @return list<OzonListingPrice>
     */
    public function parse(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !isset($decoded['items']) || !\is_array($decoded['items'])) {
            throw new \UnexpectedValueException('Ozon /v3/product/info/list response must contain an "items" array.');
        }

        $prices = [];
        foreach ($decoded['items'] as $item) {
            if (!\is_array($item)) {
                throw new \UnexpectedValueException('Ozon product info entry must be an object.');
            }

            $sku = $item['sku'] ?? null;
            if (!\is_int($sku) && !\is_string($sku)) {
                throw new \UnexpectedValueException('Ozon product info sku must be a number or a string.');
            }

            $sku = (string) $sku;
            if ('' === $sku || '0' === $sku) {
                continue;
            }

            $currency = $item['currency_code'] ?? null;
            if (!\is_string($currency) || 1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
                // Валюта обязательна и умолчания не имеет (ADR-004):
                // подставить RUB значило бы решить за площадку.
                continue;
            }

            $price = self::money($item['price'] ?? null, $currency, 'price');
            if (null === $price) {
                continue;
            }

            $prices[] = new OzonListingPrice(
                marketplaceSku: $sku,
                price: $price,
                // Ozon присылает «0.00» у товара без зачёркнутой цены —
                // это отсутствие скидки, а не скидка до нуля.
                oldPrice: self::money($item['old_price'] ?? null, $currency, 'old_price'),
            );
        }

        return $prices;
    }

    /**
     * Строка «3300.00» → Money. Целочисленный разбор без промежуточного
     * float: ADR-004 запрещает его и в промежуточных значениях.
     *
     * null — поля нет или оно пустое: цена не назначена. Значение
     * непонятного вида — исключение: значит, площадка сменила формат,
     * и молчать об этом нельзя.
     */
    private static function money(mixed $value, string $currency, string $field): ?Money
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (!\is_string($value) || 1 !== preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            throw new \UnexpectedValueException(\sprintf('Ozon product info %s must be a decimal string like "3300.00", got %s.', $field, get_debug_type($value)));
        }

        [$whole, $fraction] = explode('.', $value.'.0');
        $minor = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        if (0 === $minor) {
            return null;
        }

        return Money::ofMinor($minor, $currency);
    }
}
