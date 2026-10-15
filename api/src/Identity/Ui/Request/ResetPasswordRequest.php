<?php

declare(strict_types=1);

namespace App\Identity\Ui\Request;

final readonly class ResetPasswordRequest
{
    /** Верхняя граница хэшера Symfony: длиннее он отказывает исключением. */
    public const int MAX_PASSWORD_LENGTH = 4096;

    private function __construct(
        public string $token,
        public string $password,
    ) {
    }

    /**
     * Токен неверного формата отвечает тем же кодом, что неизвестный,
     * истёкший и использованный (ADR-037): различие наружу не выходит.
     *
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

        $token = $decoded['token'] ?? null;
        if (!\is_string($token) || 1 !== preg_match('/^[0-9a-f]{64}$/', $token)) {
            throw new \InvalidArgumentException('reset_token_invalid');
        }

        $password = $decoded['password'] ?? null;
        if (!\is_string($password) || mb_strlen($password) < SelfRegistrationRequest::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException('password_too_short');
        }
        if (\strlen($password) > self::MAX_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException('password_too_long');
        }

        return new self($token, $password);
    }
}
