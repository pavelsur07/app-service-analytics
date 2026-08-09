<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Identity\Domain\ValueObject\EncryptedCredentials;
use App\Identity\Domain\ValueObject\MarketplaceCredentials;

/**
 * Интерфейс в Domain, реализация (sodium, мастер-ключ из окружения) —
 * в Infrastructure/Crypto\CredentialsCipher. Application зависит от этого
 * интерфейса, не от конкретного шифра.
 */
interface MarketplaceCredentialsEncryptor
{
    public function encrypt(MarketplaceCredentials $credentials): EncryptedCredentials;

    public function decrypt(string $ciphertextBase64, int $keyVersion): MarketplaceCredentials;
}
