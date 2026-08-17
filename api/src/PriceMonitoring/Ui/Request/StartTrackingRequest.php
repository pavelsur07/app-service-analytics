<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Ui\Request;

/**
 * DTO запроса, не Symfony Form (docs/patterns.md, «HTTP-слой»).
 *
 * Артикул — единственное поле: кабинет определяет сервер
 * (`StartTrackingAction`), автора ставит `CompanyAccessSubscriber`.
 *
 * Форма артикула проверяется здесь той же маской, что стоит в требованиях
 * маршрутов по `{marketplaceSku}`: артикул уезжает в SQL параметром
 * и инъекции не даёт, но строка на килобайт превратилась бы в строку
 * на килобайт в базе, а колонка — varchar(64).
 */
final readonly class StartTrackingRequest
{
    public const string SKU_PATTERN = '[A-Za-z0-9_-]{1,64}';

    private function __construct(
        public string $marketplaceSku,
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

        return new self($sku);
    }
}
