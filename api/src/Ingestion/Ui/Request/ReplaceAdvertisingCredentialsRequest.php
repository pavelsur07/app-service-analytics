<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Request;

/**
 * Тело ввода рекламного ключа (ADR-026, п. 1): client_id и client_secret
 * сервисного аккаунта Performance API и версия подключения (ADR-008).
 */
final readonly class ReplaceAdvertisingCredentialsRequest
{
    private function __construct(
        public string $clientId,
        public string $clientSecret,
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
        $clientSecret = $decoded['clientSecret'] ?? null;

        if (!\is_string($clientId) || '' === trim($clientId)) {
            throw new \InvalidArgumentException('client_id_required');
        }

        if (!\is_string($clientSecret) || '' === trim($clientSecret)) {
            throw new \InvalidArgumentException('client_secret_required');
        }

        $version = $decoded['version'] ?? null;
        if (!\is_int($version)) {
            throw new \InvalidArgumentException('version_required');
        }

        return new self(trim($clientId), trim($clientSecret), $version);
    }
}
