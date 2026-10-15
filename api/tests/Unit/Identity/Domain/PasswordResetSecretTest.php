<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\ValueObject\PasswordResetSecret;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-037: открытый токен существует только до отправки письма, база
 * получает его SHA-256, а ссылка живёт час.
 */
final class PasswordResetSecretTest extends TestCase
{
    public function testGeneratedSecretsAre256BitHexAndPairwiseDistinct(): void
    {
        $plainTexts = [];

        for ($i = 0; $i < 100; ++$i) {
            $plainText = PasswordResetSecret::generate()->plainText();

            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plainText);
            $plainTexts[] = $plainText;
        }

        self::assertCount(100, array_unique($plainTexts));
    }

    public function testHashIsLowercaseSha256OfPlaintext(): void
    {
        $secret = PasswordResetSecret::fromPlainText('one-time-secret');

        self::assertSame(hash('sha256', 'one-time-secret'), $secret->hash());
        self::assertNotSame($secret->plainText(), $secret->hash());
    }

    public function testEmptySecretIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PasswordResetSecret::fromPlainText('');
    }

    public function testTokenExpiresOneHourAfterIssue(): void
    {
        $issuedAt = new \DateTimeImmutable('2026-10-15 10:00:00');

        $token = PasswordResetToken::issue(Uuid::v7(), str_repeat('a', 64), $issuedAt);

        self::assertEquals(new \DateTimeImmutable('2026-10-15 11:00:00'), $token->expiresAt());
    }

    public function testTokenRejectsPlaintextInsteadOfHash(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PasswordResetToken::issue(Uuid::v7(), 'not-a-hash', new \DateTimeImmutable());
    }
}
