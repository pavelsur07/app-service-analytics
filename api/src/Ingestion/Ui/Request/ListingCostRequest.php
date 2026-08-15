<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Request;

use Symfony\Component\Uid\Uuid;

/**
 * DTO запроса, не Symfony Form (docs/patterns.md, «HTTP-слой»).
 *
 * Сумма приходит в минорных единицах и целым числом. Рубли с копейками
 * дробным числом сюда не принимаются вовсе: JSON-число — это double,
 * и `420.10` приезжает как 420.09999999999997. ADR-004 запрещает float
 * в денежных вычислениях, и граница, где это проще всего нарушить
 * незаметно, — ровно здесь. Разбор строки «420,10» — забота экрана.
 */
final readonly class ListingCostRequest
{
    private function __construct(
        public string $marketplaceAccountId,
        public string $marketplaceSku,
        public \DateTimeImmutable $effectiveFrom,
        public int $unitCostMinor,
        public string $currency,
    ) {
    }

    /**
     * @throws \InvalidArgumentException с кодом ошибки для ответа 422
     */
    public static function fromJson(string $body): self
    {
        $decoded = self::decode($body);

        // Формат проверяется здесь, а не в сценарии: там строка уходит
        // в Uuid::fromString, и мусор дал бы 500 вместо 422 — отказ,
        // который выглядит как поломка сервиса.
        $accountId = $decoded['marketplaceAccountId'] ?? null;
        if (!\is_string($accountId) || !Uuid::isValid(trim($accountId))) {
            throw new \InvalidArgumentException('marketplace_account_id_required');
        }

        $sku = $decoded['marketplaceSku'] ?? null;
        if (!\is_string($sku) || '' === trim($sku)) {
            throw new \InvalidArgumentException('marketplace_sku_required');
        }

        $rawDate = $decoded['effectiveFrom'] ?? null;
        if (!\is_string($rawDate)) {
            throw new \InvalidArgumentException('effective_from_required');
        }

        $effectiveFrom = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
        if (false === $effectiveFrom || $effectiveFrom->format('Y-m-d') !== $rawDate) {
            // «!» обнуляет время, вторая проверка ловит 2026-02-30:
            // такая дата разбирается и молча переезжает на 2 марта.
            throw new \InvalidArgumentException('effective_from_invalid');
        }

        return new self(
            trim($accountId),
            trim($sku),
            $effectiveFrom,
            self::unitCostMinor($decoded),
            self::currency($decoded),
        );
    }

    /**
     * Тело исправления: сумма и версия. Ни карточка, ни дата начала
     * действия не меняются — это была бы уже другая позиция, а не
     * исправление этой.
     *
     * @return array{unitCostMinor: int, currency: string, version: int}
     *
     * @throws \InvalidArgumentException
     */
    public static function correctionFromJson(string $body): array
    {
        $decoded = self::decode($body);

        // Версия обязательна (ADR-008): принимать изменение «без версии»
        // как безусловное правила прямо запрещают.
        $version = $decoded['version'] ?? null;
        if (!\is_int($version)) {
            throw new \InvalidArgumentException('version_required');
        }

        return [
            'unitCostMinor' => self::unitCostMinor($decoded),
            'currency' => self::currency($decoded),
            'version' => $version,
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('malformed_json');
        }

        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('malformed_json');
        }

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $decoded
     */
    private static function unitCostMinor(array $decoded): int
    {
        $amount = $decoded['unitCostMinor'] ?? null;
        if (!\is_int($amount)) {
            throw new \InvalidArgumentException('unit_cost_minor_required');
        }

        if ($amount < 0) {
            // Отрицательная закупка — опечатка, а не скидка. Расходы
            // площадки приходят со своим знаком и живут в другой
            // таблице (ADR-012).
            throw new \InvalidArgumentException('unit_cost_negative');
        }

        return $amount;
    }

    /**
     * @param array<array-key, mixed> $decoded
     */
    private static function currency(array $decoded): string
    {
        $currency = $decoded['currency'] ?? null;
        if (!\is_string($currency) || 1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            // Валюта обязательна и умолчания не имеет (ADR-004):
            // подставить RUB значило бы решить за клиента, в чём он
            // считает закупку.
            throw new \InvalidArgumentException('currency_required');
        }

        return $currency;
    }
}
