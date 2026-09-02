<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\EmailVerificationToken;
use App\Identity\Domain\EmailVerificationTokenRepository;
use App\Identity\Domain\RegistrationEmailSender;
use App\Identity\Domain\UserRepository;
use App\Identity\Domain\ValueObject\EmailVerificationSecret;

final readonly class ResendEmailVerificationAction
{
    public function __construct(
        private UserRepository $users,
        private EmailVerificationTokenRepository $tokens,
        private RegistrationEmailSender $registrationEmails,
    ) {
    }

    public function __invoke(string $email, \DateTimeImmutable $now): void
    {
        // Здесь поиск допустим: пользователь явно просит повтор, а вставки
        // аккаунта нет. Наружный ответ всё равно не раскрывает результат.
        $user = $this->users->findByEmail($email);
        if (null === $user) {
            return;
        }

        if (null !== $user->emailConfirmedAt()) {
            $this->registrationEmails->sendAlreadyRegistered($user->email());

            return;
        }

        $secret = EmailVerificationSecret::generate();
        $this->tokens->add(EmailVerificationToken::issue($user->id(), $secret->hash(), $now));
        $this->registrationEmails->sendConfirmation($user->email(), $secret);
    }
}
