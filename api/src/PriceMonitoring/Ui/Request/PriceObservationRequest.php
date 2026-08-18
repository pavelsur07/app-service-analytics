<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Ui\Request;

/**
 * DTO запроса, не Symfony Form (docs/patterns.md, «HTTP-слой»).
 *
 * Тело приходит от кода на машине клиента — это граница доверия,
 * и проверяется здесь всё, что дальше уедет в базу.
 *
 * Цена одна — витринная (ADR-015). Цену продавца расширение прислать
 * не может: на карточке её нет, она приходит из каталога и живёт
 * историей в Ingestion.
 *
 * Сумма — целые минорные единицы. Рубли с копейками дробным числом
 * не принимаются вовсе: JSON-число это double, и `420.10` приезжает
 * как 420.09999999999997 (ADR-004 запрещает float в денежных величинах,
 * а граница, где это проще всего нарушить незаметно, — ровно здесь).
 */
final readonly class PriceObservationRequest
{
    public const string SKU_PATTERN = '[A-Za-z0-9_-]{1,64}';

    /**
     * Потолок на версию расширения — колонка varchar(32). Значение
     * приходит из манифеста на чужой машине, то есть может быть любым.
     */
    private const int MAX_VERSION_LENGTH = 32;

    private function __construct(
        public string $marketplaceSku,
        public \DateTimeImmutable $observedAt,
        public int $displayedPriceMinor,
        public string $currency,
        public string $extensionVersion,
    ) {
    }

    /**
     * @throws \InvalidArgumentException с кодом ошибки для ответа 422
     */
    public static function fromJson(string $body): self
    {
        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('malformed_json');
        }

        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('malformed_json');
        }

        $sku = $decoded['marketplaceSku'] ?? null;
        if (!\is_string($sku) || 1 !== preg_match('/^'.self::SKU_PATTERN.'$/', $sku)) {
            throw new \InvalidArgumentException('marketplace_sku_required');
        }

        $version = $decoded['extensionVersion'] ?? null;
        if (!\is_string($version) || '' === trim($version) || \strlen($version) > self::MAX_VERSION_LENGTH) {
            throw new \InvalidArgumentException('extension_version_required');
        }

        $displayed = self::price($decoded, 'displayedPrice', 'displayed_price');

        return new self(
            $sku,
            self::observedAt($decoded),
            $displayed['amount'],
            $displayed['currency'],
            trim($version),
        );
    }

    /**
     * @param array<array-key, mixed> $decoded
     */
    private static function observedAt(array $decoded): \DateTimeImmutable
    {
        $raw = $decoded['observedAt'] ?? null;
        if (!\is_string($raw)) {
            throw new \InvalidArgumentException('observed_at_required');
        }

        // Строго ISO 8601 в UTC — тот формат, который отдаёт
        // `Date.prototype.toISOString()` в расширении. Свободный разбор
        // принял бы «вчера» или дату без пояса и молча положил бы
        // в ключ строки момент из чужого часового пояса.
        $utc = new \DateTimeZone('UTC');
        // Два формата, потому что Date.toISOString() даёт миллисекунды,
        // а рукописный и сериализованный на сервере момент — нет.
        foreach (['Y-m-d\TH:i:s.v\Z', 'Y-m-d\TH:i:s\Z'] as $format) {
            $observedAt = \DateTimeImmutable::createFromFormat($format, $raw, $utc);
            if (false !== $observedAt) {
                return $observedAt;
            }
        }

        throw new \InvalidArgumentException('observed_at_invalid');
    }

    /**
     * $field — имя поля в теле запроса, $code — префикс кода ошибки.
     * Разные, потому что тело следует стилю JSON, а коды ошибок — стилю
     * остальных кодов этого API.
     *
     * @param array<array-key, mixed> $decoded
     *
     * @return array{amount: int, currency: string}
     */
    private static function price(array $decoded, string $field, string $code): array
    {
        $price = $decoded[$field] ?? null;
        if (!\is_array($price)) {
            throw new \InvalidArgumentException($code.'_required');
        }

        $amount = $price['amount'] ?? null;
        if (!\is_int($amount)) {
            throw new \InvalidArgumentException($code.'_required');
        }
        if ($amount < 0) {
            // Отрицательная цена на витрине — не скидка, а ошибка разбора
            // страницы. СПП, который может быть отрицательным, считается
            // при чтении и здесь ни при чём.
            throw new \InvalidArgumentException($code.'_negative');
        }

        $currency = $price['currency'] ?? null;
        if (!\is_string($currency) || 1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            // Валюта обязательна и умолчания не имеет (ADR-004).
            throw new \InvalidArgumentException($code.'_currency_required');
        }

        return ['amount' => $amount, 'currency' => $currency];
    }
}
