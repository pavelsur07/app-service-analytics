<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\EmailVerificationTokenRepository;
use App\Identity\Domain\ValueObject\EmailVerificationSecret;

/**
 * Узкая pre-auth lifecycle-граница (CLAUDE.md §1, ADR-021): предъявленный
 * одноразовый секрет определяет пользователя только внутри атомарного
 * перехода, а наружу выходят лишь исход и пользователь для его сессии.
 */
final readonly class ConfirmEmailAction
{
    public function __construct(
        private EmailVerificationTokenRepository $tokens,
    ) {
    }

    public function __invoke(EmailVerificationSecret $secret, \DateTimeImmutable $now): EmailConfirmationResult
    {
        $transition = $this->tokens->confirm($secret->hash(), $now);

        return new EmailConfirmationResult($transition->outcome, $transition->user);
    }
}
