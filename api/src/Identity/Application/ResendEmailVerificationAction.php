<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\EmailVerificationLifecycleGuard;
use App\Identity\Domain\EmailVerificationToken;
use App\Identity\Domain\EmailVerificationTokenRepository;
use App\Identity\Domain\EmailVerificationUserByEmailQuery;
use App\Identity\Domain\RegistrationEmailSender;
use App\Identity\Domain\ValueObject\EmailVerificationSecret;

/**
 * Узкая pre-auth lifecycle-граница (CLAUDE.md §1, ADR-021). Email здесь
 * не авторизует чтение: результат lookup не возвращается, а каждая ветка
 * выполняет один синхронный SMTP-вызов с одинаковым публичным ответом.
 */
final readonly class ResendEmailVerificationAction
{
    public function __construct(
        private EmailVerificationUserByEmailQuery $users,
        private EmailVerificationTokenRepository $tokens,
        private RegistrationEmailSender $registrationEmails,
        private EmailVerificationLifecycleGuard $lifecycle,
    ) {
    }

    public function __invoke(string $email, \DateTimeImmutable $now): void
    {
        $this->lifecycle->runShared(function () use ($email, $now): void {
            // Здесь поиск допустим: пользователь явно просит повтор, а вставки
            // аккаунта нет. Наружный ответ всё равно не раскрывает результат.
            $user = $this->users->findForResend($email);
            if (null === $user) {
                // SMTP-вызов есть во всех трёх ветках: иначе status/timing
                // ответа превращается в oracle существования аккаунта.
                $this->registrationEmails->sendNoAccountFound($email);

                return;
            }

            if (null !== $user->emailConfirmedAt()) {
                $this->registrationEmails->sendAlreadyRegistered($user->email());

                return;
            }

            $secret = EmailVerificationSecret::generate();
            $this->tokens->add(EmailVerificationToken::issue($user->id(), $secret->hash(), $now));
            $this->registrationEmails->sendConfirmation($user->email(), $secret);
        });
    }
}
