<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Crypto;

use App\Identity\Domain\ValueObject\MarketplaceCredentials;
use App\Identity\Infrastructure\Crypto\CredentialsCipher;
use PHPUnit\Framework\TestCase;

final class CredentialsCipherTest extends TestCase
{
    private function cipher(): CredentialsCipher
    {
        // Конструктору соответствует уже раскодированный ключ: в проде его
        // decode-ит env-процессор `base64:` до инъекции (см. #[Autowire]).
        return new CredentialsCipher(random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function testDecryptReturnsWhatWasEncrypted(): void
    {
        $cipher = $this->cipher();
        $credentials = MarketplaceCredentials::fromArray(['client_id' => '123', 'api_key' => 'secret']);

        $encrypted = $cipher->encrypt($credentials);
        $decrypted = $cipher->decrypt($encrypted->ciphertext, $encrypted->keyVersion);

        self::assertSame(['client_id' => '123', 'api_key' => 'secret'], $decrypted->toArray());
    }

    public function testCiphertextDoesNotContainPlaintext(): void
    {
        $encrypted = $this->cipher()->encrypt(MarketplaceCredentials::fromArray(['api_key' => 'super-secret-value']));

        self::assertStringNotContainsString('super-secret-value', $encrypted->ciphertext);
    }

    public function testDecryptRejectsUnsupportedKeyVersion(): void
    {
        $encrypted = $this->cipher()->encrypt(MarketplaceCredentials::fromArray(['api_key' => 'secret']));

        $this->expectException(\RuntimeException::class);

        $this->cipher()->decrypt($encrypted->ciphertext, 999);
    }

    public function testDecryptRejectsTamperedCiphertext(): void
    {
        $cipher = $this->cipher();
        $encrypted = $cipher->encrypt(MarketplaceCredentials::fromArray(['api_key' => 'secret']));

        $tampered = substr($encrypted->ciphertext, 0, -4).'AAAA';

        $this->expectException(\RuntimeException::class);

        $cipher->decrypt($tampered, $encrypted->keyVersion);
    }

    public function testDecryptWithDifferentCipherInstanceFailsAuthentication(): void
    {
        $encrypted = $this->cipher()->encrypt(MarketplaceCredentials::fromArray(['api_key' => 'secret']));

        $this->expectException(\RuntimeException::class);

        // Другой мастер-ключ — как если бы ротация ключа прошла без
        // перешифровки этой записи.
        $this->cipher()->decrypt($encrypted->ciphertext, $encrypted->keyVersion);
    }
}
