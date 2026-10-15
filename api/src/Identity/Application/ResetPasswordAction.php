<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\PasswordResetEmailSender;
use App\Identity\Domain\PasswordResetTokenRepository;
use App\Identity\Domain\ValueObject\PasswordResetSecret;

/**
 * Узкая pre-auth lifecycle-граница (CLAUDE.md §1, ADR-037): предъявленный
 * одноразовый секрет определяет пользователя только внутри атомарного
 * перехода. Наружу выходит лишь «сменён / не сменён»; сессия не открывается.
 */
final readonly class ResetPasswordAction
{
    public function __construct(
        private PasswordResetTokenRepository $tokens,
        private PasswordResetEmailSender $resetEmails,
    ) {
    }

    public function __invoke(PasswordResetSecret $secret, string $passwordHash, \DateTimeImmutable $now): bool
    {
        $email = $this->tokens->resetPassword($secret->hash(), $passwordHash, $now);
        if (null === $email) {
            return false;
        }

        $this->resetEmails->sendPasswordChanged($email);

        return true;
    }
}
