<?php

declare(strict_types=1);

namespace App\Identity\Domain\ValueObject;

/**
 * Одноразовый секрет подтверждения email (ADR-021).
 *
 * Открытый текст существует только между выпуском и отправкой письма.
 * В базу уходит только SHA-256; строкового представления и сериализации
 * намеренно нет, чтобы секрет не попал в лог неявно.
 */
final readonly class EmailVerificationSecret
{
    private const int RANDOM_BYTES = 32;

    private function __construct(
        private string $plainText,
    ) {
        if ('' === $plainText) {
            throw new \InvalidArgumentException('Email verification secret must not be empty.');
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
