<?php

declare(strict_types=1);

namespace App\Identity\Ui\Request;

final readonly class SelfRegistrationRequest
{
    public const int MIN_PASSWORD_LENGTH = 12;
    public const int MAX_COMPANY_NAME_LENGTH = 255;

    private function __construct(
        public string $email,
        public string $password,
        public string $companyName,
        public bool $legalConsent,
    ) {
    }

    /**
     * @throws \InvalidArgumentException с кодом безопасной ошибки
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

        $email = $decoded['email'] ?? null;
        if (!\is_string($email) || false === filter_var(trim($email), \FILTER_VALIDATE_EMAIL) || mb_strlen(trim($email)) > 255) {
            throw new \InvalidArgumentException('email_invalid');
        }

        $password = $decoded['password'] ?? null;
        if (!\is_string($password) || mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException('password_too_short');
        }

        $companyName = $decoded['companyName'] ?? null;
        if (!\is_string($companyName) || '' === trim($companyName) || mb_strlen(trim($companyName)) > self::MAX_COMPANY_NAME_LENGTH) {
            throw new \InvalidArgumentException('company_name_invalid');
        }

        if (true !== ($decoded['legalConsent'] ?? null)) {
            throw new \InvalidArgumentException('legal_consent_required');
        }

        return new self(trim($email), $password, trim($companyName), true);
    }
}
