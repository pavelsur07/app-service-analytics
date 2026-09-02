<?php

declare(strict_types=1);

namespace App\Identity\Ui\Request;

final readonly class ResendEmailVerificationRequest
{
    private function __construct(
        public string $email,
    ) {
    }

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

        return new self(trim($email));
    }
}
