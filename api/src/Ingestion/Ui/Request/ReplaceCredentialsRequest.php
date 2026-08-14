<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Request;

/**
 * DTO запроса, не Symfony Form (docs/patterns.md, «HTTP-слой»).
 *
 * Проверки здесь ручные, а не через Symfony Validator: пакета в проекте
 * нет, а ради двух непустых строк тянуть зависимость — плата без выгоды.
 * Появится третий эндпоинт с телом посложнее — это будет поводом
 * поставить валидатор и переписать разбор здесь.
 *
 * Значения в исключения и сообщения не попадают: api_key — секрет,
 * и текст ошибки с его фрагментом уедет в трекер и в логи.
 */
final readonly class ReplaceCredentialsRequest
{
    private function __construct(
        public string $clientId,
        public string $apiKey,
        public int $version,
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

        $clientId = $decoded['clientId'] ?? null;
        $apiKey = $decoded['apiKey'] ?? null;

        if (!\is_string($clientId) || '' === trim($clientId)) {
            throw new \InvalidArgumentException('client_id_required');
        }

        if (!\is_string($apiKey) || '' === trim($apiKey)) {
            throw new \InvalidArgumentException('api_key_required');
        }

        // Версия обязательна (ADR-008): «принимать изменение без версии
        // как безусловное запрещено — это возвращает исходную проблему».
        $version = $decoded['version'] ?? null;
        if (!\is_int($version)) {
            throw new \InvalidArgumentException('version_required');
        }

        return new self(trim($clientId), trim($apiKey), $version);
    }
}
