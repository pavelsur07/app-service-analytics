<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Notification;

use App\Identity\Domain\PasswordResetEmailSender;
use App\Identity\Domain\ValueObject\PasswordResetSecret;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Письма сброса пароля (ADR-037) — синхронно, мимо Messenger, по той же
 * причине, что письма регистрации: открытый токен не должен попасть
 * в messenger_messages и резервные копии. Токен — только во fragment URL,
 * который браузер не отправляет серверу.
 */
final readonly class MailPasswordResetEmailSender implements PasswordResetEmailSender
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private string $sellerAppOrigin,
    ) {
    }

    public function sendResetLink(string $email, PasswordResetSecret $secret): void
    {
        $url = rtrim($this->sellerAppOrigin, '/').'/reset-password#token='.rawurlencode($secret->plainText());

        $this->mailer->send(
            (new Email())
                ->to($email)
                ->subject('Conwix: восстановление пароля')
                ->text(
                    "Чтобы задать новый пароль, перейдите по ссылке.\n\n"
                    ."Ссылка действует 1 час и только один раз:\n{$url}\n\n"
                    .'Если вы не запрашивали восстановление пароля, просто проигнорируйте это письмо — '
                    ."пароль останется прежним.\n",
                ),
        );
    }

    public function sendNoAccountFound(string $email): void
    {
        $this->mailer->send(
            (new Email())
                ->to($email)
                ->subject('Conwix: восстановление пароля')
                ->text(
                    'Получен запрос на восстановление пароля для этого адреса, '
                    ."но аккаунта Conwix с ним нет.\n\n"
                    ."Возможно, вы регистрировались с другим адресом.\n"
                    ."Если вы не отправляли этот запрос, просто проигнорируйте письмо.\n",
                ),
        );
    }

    public function sendPasswordChanged(string $email): void
    {
        try {
            $this->mailer->send(
                (new Email())
                    ->to($email)
                    ->subject('Conwix: пароль изменён')
                    ->text(
                        'Пароль от вашего аккаунта Conwix только что изменён, '
                        ."все прежние сеансы входа завершены.\n\n"
                        ."Если это были не вы, срочно свяжитесь с поддержкой.\n",
                    ),
            );
        } catch (TransportExceptionInterface $failure) {
            // Пароль уже изменён и зафиксирован: отказ уведомления не делает
            // сброс неуспешным, но и не проходит молча.
            $this->logger->warning('Password changed notification was not sent', ['exception' => $failure]);
        }
    }
}
