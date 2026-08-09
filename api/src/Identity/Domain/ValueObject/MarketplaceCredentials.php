<?php

declare(strict_types=1);

namespace App\Identity\Domain\ValueObject;

/**
 * Учётные данные подключения к площадке — объект, не строка (ADR-002):
 * у площадки несколько токенов с разными областями действия. Хранится
 * только в расшифрованном виде в памяти; персистентность — через
 * CredentialsCipher, VO об этом не знает.
 */
final readonly class MarketplaceCredentials
{
    /**
     * @param array<string, string> $values
     */
    private function __construct(
        private array $values,
    ) {
    }

    /**
     * @param array<string, string> $values
     */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public function get(string $key): string
    {
        return $this->values[$key] ?? throw new \OutOfBoundsException("Credentials key '{$key}' is not set.");
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
