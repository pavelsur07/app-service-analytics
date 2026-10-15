<?php

declare(strict_types=1);

namespace App\Identity\Domain\ValueObject;

/**
 * Одноразовый секрет сброса пароля (ADR-037).
 *
 * Отдельный тип, а не EmailVerificationSecret: сигнатуры двух сценариев
 * не должны принимать секрет соседнего. Открытый текст существует только
 * между выпуском и отправкой письма; в базу уходит только SHA-256.
 */
final readonly class PasswordResetSecret
{
    private const int RANDOM_BYTES = 32;

    private function __construct(
        private string $plainText,
    ) {
        if ('' === $plainText) {
            throw new \InvalidArgumentException('Password reset secret must not be empty.');
        }
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(self::RANDOM_BYTES)));
    }

    public static function fromPlainText(string $plainText): self
    {
        return new self($plainText);
    }

    public function plainText(): string
    {
        return $this->plainText;
    }

    public function hash(): string
    {
        return hash('sha256', $this->plainText);
    }
}
