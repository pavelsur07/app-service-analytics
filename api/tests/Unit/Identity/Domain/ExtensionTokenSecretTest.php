<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\ValueObject\ExtensionTokenSecret;
use PHPUnit\Framework\TestCase;

/**
 * ADR-010: в базу уходит только хэш, открытый текст живёт один ответ.
 */
final class ExtensionTokenSecretTest extends TestCase
{
    public function testGeneratedSecretsAreDistinct(): void
    {
        self::assertNotSame(
            ExtensionTokenSecret::generate()->plaintext(),
            ExtensionTokenSecret::generate()->plaintext(),
        );
    }

    public function testPlaintextCarriesRecognisablePrefix(): void
    {
        // Опознаваемый префикс — чтобы утёкшая строка находилась в логах
        // и сканерами секретов, а не выглядела случайным набором символов.
        self::assertStringStartsWith(ExtensionTokenSecret::PREFIX, ExtensionTokenSecret::generate()->plaintext());
    }

    public function testPlaintextIsUrlSafe(): void
    {
        // Токен уезжает в заголовок Authorization и хранится в
        // chrome.storage — символы, требующие экранирования, там ни к чему.
        self::assertMatchesRegularExpression('/^conwix_ext_[A-Za-z0-9_-]+$/', ExtensionTokenSecret::generate()->plaintext());
    }

    public function testHashIsSha256OfPlaintext(): void
    {
        $secret = ExtensionTokenSecret::generate();

        self::assertSame(hash('sha256', $secret->plaintext()), $secret->hash());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret->hash());
    }

    public function testHashOfMatchesInstanceHash(): void
    {
        // Проверяющая сторона (ExtensionTokenHandler) видит только строку
        // и считает хэш статически — он обязан совпасть с тем, что легло
        // в базу при выпуске, иначе не сойдётся ни один токен.
        $secret = ExtensionTokenSecret::generate();

        self::assertSame($secret->hash(), ExtensionTokenSecret::hashOf($secret->plaintext()));
    }

    public function testDisplayPrefixIsShorterThanSecretAndFitsTheColumn(): void
    {
        $secret = ExtensionTokenSecret::generate();
        $displayPrefix = $secret->displayPrefix();

        self::assertSame($displayPrefix, substr($secret->plaintext(), 0, \strlen($displayPrefix)));
        self::assertNotSame($secret->plaintext(), $displayPrefix);
        // token_prefix — varchar(32) в extension_token.
        self::assertLessThanOrEqual(32, \strlen($displayPrefix));
    }
}
