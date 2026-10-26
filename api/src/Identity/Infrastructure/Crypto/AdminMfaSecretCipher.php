<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Crypto;

use App\Identity\Domain\AdminMfaEncryption;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class AdminMfaSecretCipher implements AdminMfaEncryption
{
    private string $key;

    public function __construct(#[Autowire(env: 'base64:APP_ENCRYPTION_MASTER_KEY')] string $masterKey)
    {
        if (\SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($masterKey)) {
            throw new \InvalidArgumentException('A valid encryption master key is required for administrator MFA.');
        }
        $this->key = hash_hkdf('sha256', $masterKey, \SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'conwix.admin-mfa.v1');
    }

    public function encrypt(string $secret): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'v1:'.base64_encode($nonce.sodium_crypto_secretbox($secret, $nonce, $this->key));
    }

    public function decrypt(string $encrypted): string
    {
        $packed = str_starts_with($encrypted, 'v1:') ? base64_decode(substr($encrypted, 3), true) : false;
        if (false === $packed || \strlen($packed) < \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + \SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \UnexpectedValueException('Invalid encrypted MFA secret.');
        }
        $plain = sodium_crypto_secretbox_open(substr($packed, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($packed, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key);
        if (false === $plain) {
            throw new \UnexpectedValueException('Cannot decrypt MFA secret.');
        }

        return $plain;
    }
}
