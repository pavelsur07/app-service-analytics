<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Crypto;

use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\ValueObject\EncryptedCredentials;
use App\Identity\Domain\ValueObject\MarketplaceCredentials;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Симметричное шифрование учётных данных площадок (ADR-007). Мастер-ключ —
 * из секретов окружения (api/.env.local в dev, вне репозитория в прод),
 * никогда не в базе. sodium — расширение ядра PHP, не внешняя зависимость.
 *
 * Не оформлен Doctrine Type намеренно: типам Doctrine не внедряется DI-сервис
 * с секретом конструктором, а инъекция через статику скрывает зависимость.
 * Шифрование выполняет вызывающий код Application-слоя через интерфейс
 * MarketplaceCredentialsEncryptor, Entity хранит уже готовый шифротекст
 * как непрозрачную строку.
 */
final readonly class CredentialsCipher implements MarketplaceCredentialsEncryptor
{
    private const int CURRENT_KEY_VERSION = 1;

    public function __construct(
        #[Autowire(env: 'base64:APP_ENCRYPTION_MASTER_KEY')]
        private string $masterKey,
    ) {
    }

    public function encrypt(MarketplaceCredentials $credentials): EncryptedCredentials
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = json_encode($credentials->toArray(), \JSON_THROW_ON_ERROR);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->masterKey);

        return new EncryptedCredentials(
            ciphertext: base64_encode($nonce.$ciphertext),
            keyVersion: self::CURRENT_KEY_VERSION,
        );
    }

    public function decrypt(string $ciphertextBase64, int $keyVersion): MarketplaceCredentials
    {
        if (self::CURRENT_KEY_VERSION !== $keyVersion) {
            throw new \RuntimeException("Unsupported credentials key version {$keyVersion}.");
        }

        $raw = base64_decode($ciphertextBase64, true);
        if (false === $raw) {
            throw new \RuntimeException('Credentials ciphertext is not valid base64.');
        }

        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->masterKey);
        if (false === $plaintext) {
            throw new \RuntimeException('Credentials ciphertext failed authentication.');
        }

        /** @var array<string, string> $values */
        $values = json_decode($plaintext, true, flags: \JSON_THROW_ON_ERROR);

        return MarketplaceCredentials::fromArray($values);
    }
}
