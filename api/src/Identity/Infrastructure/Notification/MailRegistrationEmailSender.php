<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Notification;

use App\Identity\Domain\RegistrationEmailSender;
use App\Identity\Domain\ValueObject\EmailVerificationSecret;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Письма регистрации отправляются штатным Symfony Mailer и маршрутизируются
 * в Messenger. From задаётся глобально в mailer.yaml, не дублируется здесь.
 */
final readonly class MailRegistrationEmailSender implements RegistrationEmailSender
{
    public function __construct(
        private MailerInterface $mailer,
        private string $sellerAppOrigin,
    ) {
    }

    public function sendConfirmation(string $email, EmailVerificationSecret $secret): void
    {
        $url = rtrim($this->sellerAppOrigin, '/').'/confirm-email?token='.rawurlencode($secret->plainText());

        $this->mailer->send(
            (new Email())
                ->to($email)
                ->subject('Conwix: подтвердите адрес электронной почты')
                ->text(
                    "Подтвердите адрес электронной почты, чтобы войти в Conwix.\n\n"
                    ."Ссылка действует 24 часа:\n{$url}\n",
                ),
        );
    }

    public function sendAlreadyRegistered(string $email): void
    {
        $this->mailer->send(
            (new Email())
                ->to($email)
                ->subject('Conwix: аккаунт уже существует')
                ->text(
                    "Аккаунт с этим адресом уже существует.\n\n"
                    ."Если вы не отправляли заявку на регистрацию, просто проигнорируйте это письмо.\n",
                ),
        );
    }
}
