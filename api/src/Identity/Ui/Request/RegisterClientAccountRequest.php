<?php

declare(strict_types=1);

namespace App\Identity\Ui\Request;

/**
 * DTO запроса, не Symfony Form (docs/patterns.md, «HTTP-слой»).
 * Проверки — граница доверия: тело приходит от клиента, и больше
 * проверить его негде.
 */
final readonly class RegisterClientAccountRequest
{
    public const int MIN_PASSWORD_LENGTH = 12;
    public const int MAX_NAME_LENGTH = 255;

    private function __construct(
        public string $companyName,
        public string $ownerEmail,
        public string $ownerPassword,
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
        if (!\is_string($name) || '' === trim($name) || mb_strlen(trim($name)) > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException('company_name_invalid');
        }

        $email = $decoded['ownerEmail'] ?? null;
        if (!\is_string($email) || false === filter_var(trim($email), \FILTER_VALIDATE_EMAIL) || mb_strlen(trim($email)) > 255) {
            throw new \InvalidArgumentException('owner_email_invalid');
        }

        $password = $decoded['ownerPassword'] ?? null;
        if (!\is_string($password) || mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException('owner_password_too_short');
        }

        return new self(trim($name), trim($email), $password);
    }
}
