<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Свой провайдер, не встроенный `entity` — тот обходит UserRepository
 * и обращается к Doctrine напрямую (patterns.md: "Не используем:
 * ServiceEntityRepository и автоподстановка репозитория" — тот же принцип).
 *
 * @implements UserProviderInterface<User>
 */
final readonly class UserProvider implements UserProviderInterface
{
    public function __construct(
        private UserRepository $users,
        private EmailConfirmedUserChecker $emailConfirmedUserChecker,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->users->findByEmail($identifier);
        if (null === $user) {
            throw new UserNotFoundException(\sprintf('User "%s" not found.', $identifier));
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $refreshed = $this->loadUserByIdentifier($user->getUserIdentifier());

        try {
            // Symfony user_checker проверяет новые Passport, но ContextListener
            // при восстановлении сессии только вызывает refreshUser(). Тот же
            // checker применяется здесь, чтобы старая сессия не обходила запрет.
            $this->emailConfirmedUserChecker->checkPreAuth($refreshed);
        } catch (\Symfony\Component\Security\Core\Exception\AccountStatusException) {
            $notFound = new UserNotFoundException('User cannot be restored from the session.');
            $notFound->setUserIdentifier($user->getUserIdentifier());

            throw $notFound;
        }

        return $refreshed;
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }
}
