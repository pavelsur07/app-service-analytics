<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Security;

use App\Identity\Infrastructure\Security\InternalServiceTokenHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

/**
 * Сервисный контур закрыт по умолчанию (ADR-043 п. 4): пока токен
 * в окружении не заведён или короче 32 символов, не проходит никто —
 * в том числе запрос с таким же пустым или коротким заголовком.
 */
final class InternalServiceTokenHandlerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableConfiguredTokens(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'short, presented the same' => ['short-token', 'short-token'];
        yield 'one char below minimum' => [str_repeat('a', InternalServiceTokenHandler::MIN_LENGTH - 1), str_repeat('a', InternalServiceTokenHandler::MIN_LENGTH - 1)];
    }

    #[DataProvider('unusableConfiguredTokens')]
    public function testUnusableConfiguredTokenLetsNobodyIn(string $configured, string $presented): void
    {
        $this->expectException(BadCredentialsException::class);

        new InternalServiceTokenHandler($configured)->getUserBadgeFrom($presented);
    }

    public function testMatchingTokenOfMinimumLengthIsAccepted(): void
    {
        $token = str_repeat('t', InternalServiceTokenHandler::MIN_LENGTH);

        $badge = new InternalServiceTokenHandler($token)->getUserBadgeFrom($token);

        self::assertSame(InternalServiceTokenHandler::USER, $badge->getUserIdentifier());
    }
}
