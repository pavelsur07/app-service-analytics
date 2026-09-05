<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Request;

/**
 * DTO запроса, не Symfony Form (docs/patterns.md, «HTTP-слой»), проверки
 * ручные — тем же приёмом, что в ReplaceCredentialsRequest.
 *
 * Значения в исключения и сообщения не попадают: apiKey — секрет,
 * и текст ошибки с его фрагментом уехал бы в трекер и в логи.
 */
final readonly class ConnectOzonAccountRequest
{
    private function __construct(
        public string $name,
        public string $clientId,
        public string $apiKey,
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

        $name = $decoded['name'] ?? null;
        if (!\is_string($name) || '' === trim($name)) {
            throw new \InvalidArgumentException('name_required');
        }

        $clientId = $decoded['clientId'] ?? null;
        if (!\is_string($clientId) || '' === trim($clientId)) {
            throw new \InvalidArgumentException('client_id_required');
        }

        $apiKey = $decoded['apiKey'] ?? null;
        if (!\is_string($apiKey) || '' === trim($apiKey)) {
            throw new \InvalidArgumentException('api_key_required');
        }

        return new self(trim($name), trim($clientId), trim($apiKey));
    }
}
