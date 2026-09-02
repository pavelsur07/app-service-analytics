<?php

declare(strict_types=1);

namespace App\Tests\Support\Probe;

use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Contracts\Service\ResetInterface;

final class PasswordHasherProbe implements UserPasswordHasherInterface, ResetInterface
{
    public int $hashCalls = 0;

    public function __construct(
        private readonly UserPasswordHasherInterface $inner,
    ) {
    }

    public function hashPassword(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): string
    {
        ++$this->hashCalls;

        return $this->inner->hashPassword($user, $plainPassword);
    }

    public function isPasswordValid(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): bool
    {
        return $this->inner->isPasswordValid($user, $plainPassword);
    }

    public function needsRehash(PasswordAuthenticatedUserInterface $user): bool
    {
        return $this->inner->needsRehash($user);
    }

    public function reset(): void
    {
        $this->hashCalls = 0;
    }
}
