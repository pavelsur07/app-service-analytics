<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\Administrator;
use App\Identity\Domain\AdministratorRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Свой провайдер контура администраторов — зеркало UserProvider
 * и по той же причине: встроенный `entity` обходит репозиторий
 * и ходит в Doctrine напрямую.
 *
 * Провайдер отдельный, а не общий с продавцами, потому что отдельные
 * и таблицы (ADR-007). supportsClass() ниже — не формальность:
 * именно он не даёт сессии продавца ожить на admin-firewall
 * и наоборот, если контуры когда-нибудь окажутся на одном хосте.
 *
 * @implements UserProviderInterface<Administrator>
 */
final readonly class AdminProvider implements UserProviderInterface
{
    public function __construct(
        private AdministratorRepository $administrators,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $administrator = $this->administrators->findByEmail($identifier);
        if (null === $administrator) {
            throw new UserNotFoundException(\sprintf('Administrator "%s" not found.', $identifier));
        }

        return $administrator;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof Administrator) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        // Перечитывается из базы на каждом запросе сессии: роль,
        // изменённая в базе, действует со следующего запроса, а не
        // после переоткрытия сессии.
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return Administrator::class === $class || is_subclass_of($class, Administrator::class);
    }
}
