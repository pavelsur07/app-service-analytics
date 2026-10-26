<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure;

use App\Identity\Infrastructure\Crypto\AdminMfaSecretCipher;
use PHPUnit\Framework\TestCase;

final class AdminMfaSecretCipherTest extends TestCase
{
    public function testEncryptedSecretUsesIndependentNoncesAndDetectsTampering(): void
    {
        $cipher = new AdminMfaSecretCipher(str_repeat('k', 32));
        $secret = 'SYNTHETIC-TOTP-SECRET';
        $first = $cipher->encrypt($secret);
        $second = $cipher->encrypt($secret);
        self::assertNotSame($first, $second);
        self::assertStringNotContainsString($secret, $first);
        self::assertSame($secret, $cipher->decrypt($first));
        $packed = base64_decode(substr($first, 3), true);
        self::assertIsString($packed);
        $packed[\strlen($packed) - 1] = \chr(\ord($packed[\strlen($packed) - 1]) ^ 1);
        $this->expectException(\UnexpectedValueException::class);
        $cipher->decrypt('v1:'.base64_encode($packed));
    }
}
