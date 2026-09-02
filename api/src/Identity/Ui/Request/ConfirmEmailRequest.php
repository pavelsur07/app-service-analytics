<?php

declare(strict_types=1);

namespace App\Identity\Ui\Request;

final readonly class ConfirmEmailRequest
{
    private const int MAX_TOKEN_LENGTH = 512;

    private function __construct(
        public string $token,
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

        $token = $decoded['token'] ?? null;
        if (!\is_string($token) || '' === $token || \strlen($token) > self::MAX_TOKEN_LENGTH) {
            throw new \InvalidArgumentException('token_invalid');
        }

        return new self($token);
    }
}
