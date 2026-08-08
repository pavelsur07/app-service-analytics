<?php

declare(strict_types=1);

namespace App\Identity\Domain\ValueObject;

/**
 * Результат шифрования: шифротекст + версия ключа рядом с ним (ADR-007),
 * чтобы ротация ключа не требовала единовременной перешифровки всего.
 */
final readonly class EncryptedCredentials
{
    public function __construct(
        public string $ciphertext,
        public int $keyVersion,
    ) {
    }
}
