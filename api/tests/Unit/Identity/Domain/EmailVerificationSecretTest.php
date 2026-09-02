<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\ValueObject\EmailVerificationSecret;
use PHPUnit\Framework\TestCase;

/**
 * ADR-021: открытый токен существует только до отправки письма, а база
 * получает его необратимый SHA-256 хэш.
 */
final class EmailVerificationSecretTest extends TestCase
{
    public function testGeneratedSecretsAreNonEmptyAndPairwiseDistinct(): void
    {
        $plainTexts = [];

        for ($i = 0; $i < 100; ++$i) {
            $plainText = EmailVerificationSecret::generate()->plainText();

            self::assertNotSame('', $plainText);
            $plainTexts[] = $plainText;
        }

        self::assertCount(100, array_unique($plainTexts));
    }

    public function testHashIsLowercaseSha256OfPlaintext(): void
    {
        $secret = EmailVerificationSecret::generate();

        self::assertSame(hash('sha256', $secret->plainText()), $secret->hash());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret->hash());
    }

    public function testKnownPlaintextCanBeRecreatedForConfirmation(): void
    {
        $secret = EmailVerificationSecret::fromPlainText('one-time-secret');

        self::assertSame('one-time-secret', $secret->plainText());
        self::assertSame(hash('sha256', 'one-time-secret'), $secret->hash());
    }
}
