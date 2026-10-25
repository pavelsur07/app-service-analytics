<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Токен направления «сервис → PHP» для firewall `internal_service`
 * (сервисный контур, CLAUDE.md §1; ADR-043 п. 4). Статический секрет
 * из окружения, сравнение за постоянное время.
 *
 * Закрыт по умолчанию: токен окружения короче MIN_LENGTH — в том числе
 * пустой, пока сервис не включён, — не пропускает никого. Иначе пустая
 * переменная превращала бы пустой заголовок в доступ ко всем ключам.
 */
final readonly class InternalServiceTokenHandler implements AccessTokenHandlerInterface
{
    public const string USER = 'internal-service';

    public const int MIN_LENGTH = 32;

    public function __construct(
        #[Autowire(env: 'INTERNAL_AUTH_TOKEN')]
        #[\SensitiveParameter]
        private string $expectedToken,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        if (\strlen($this->expectedToken) < self::MIN_LENGTH || !hash_equals($this->expectedToken, $accessToken)) {
            throw new BadCredentialsException('Invalid internal service token.');
        }

        return new UserBadge(self::USER);
    }
}
