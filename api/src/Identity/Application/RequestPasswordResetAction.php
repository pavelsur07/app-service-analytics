<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\EmailVerificationLifecycleGuard;
use App\Identity\Domain\EmailVerificationToken;
use App\Identity\Domain\EmailVerificationTokenRepository;
use App\Identity\Domain\PasswordResetEmailSender;
use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\PasswordResetTokenRepository;
use App\Identity\Domain\PasswordResetUserByEmailQuery;
use App\Identity\Domain\RegistrationEmailSender;
use App\Identity\Domain\ValueObject\EmailVerificationSecret;
use App\Identity\Domain\ValueObject\PasswordResetSecret;

/**
 * Узкая pre-auth lifecycle-граница (CLAUDE.md §1, ADR-037). Email здесь
 * не авторизует чтение: результат lookup не возвращается, а каждая ветка
 * выполняет ровно один синхронный SMTP-вызов с одинаковым публичным ответом.
 */
final readonly class RequestPasswordResetAction
{
    public function __construct(
        private PasswordResetUserByEmailQuery $users,
        private PasswordResetTokenRepository $tokens,
        private PasswordResetEmailSender $resetEmails,
        private EmailVerificationTokenRepository $verificationTokens,
        private RegistrationEmailSender $registrationEmails,
        private EmailVerificationLifecycleGuard $lifecycle,
    ) {
    }

    public function __invoke(string $email, \DateTimeImmutable $now): void
    {
        // Shared-секция, как у resend: ручная уборка неподтверждённых
        // аккаунтов не удалит пользователя между поиском и выпуском ссылки.
        $this->lifecycle->runShared(function () use ($email, $now): void {
            $user = $this->users->findForPasswordReset($email);
            if (null === $user) {
                $this->resetEmails->sendNoAccountFound($email);

                return;
            }

            // Сброс не обходит подтверждение адреса (ADR-021): человеку
            // с неподтверждённым адресом нужна ссылка подтверждения.
            if (null === $user->emailConfirmedAt()) {
                $secret = EmailVerificationSecret::generate();
                $this->verificationTokens->add(EmailVerificationToken::issue($user->id(), $secret->hash(), $now));
                $this->registrationEmails->sendConfirmation($user->email(), $secret);

                return;
            }

            $secret = PasswordResetSecret::generate();
            $this->tokens->issue(PasswordResetToken::issue($user->id(), $secret->hash(), $now));
            $this->resetEmails->sendResetLink($user->email(), $secret);
        });
    }
}
