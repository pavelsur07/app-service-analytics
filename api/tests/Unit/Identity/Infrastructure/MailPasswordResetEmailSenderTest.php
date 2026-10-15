<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure;

use App\Identity\Domain\ValueObject\PasswordResetSecret;
use App\Identity\Infrastructure\Notification\MailPasswordResetEmailSender;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * ADR-037: ссылка несёт токен только во fragment, а отказ письма-уведомления
 * после уже зафиксированной смены пароля пишется в журнал, но не превращает
 * успешный сброс в ошибку.
 */
final class MailPasswordResetEmailSenderTest extends TestCase
{
    public function testResetLinkCarriesTokenOnlyInFragment(): void
    {
        $mailer = new RecordingMailer();
        $secret = PasswordResetSecret::generate();

        (new MailPasswordResetEmailSender($mailer, new Logger('test'), 'https://app.example.test/'))
            ->sendResetLink('owner@example.test', $secret);

        self::assertCount(1, $mailer->sent);
        $body = (string) $mailer->sent[0]->getTextBody();
        self::assertStringContainsString('https://app.example.test/reset-password#token='.$secret->plainText(), $body);
        self::assertStringNotContainsString('?token=', $body);
        self::assertStringNotContainsString($secret->hash(), $body);
    }

    public function testNotificationFailureIsLoggedAndSwallowed(): void
    {
        $handler = new TestHandler();
        $mailer = new RecordingMailer(new TransportException('smtp down'));

        (new MailPasswordResetEmailSender($mailer, new Logger('test', [$handler]), 'https://app.example.test'))
            ->sendPasswordChanged('owner@example.test');

        self::assertTrue($handler->hasWarningThatContains('Password changed notification was not sent'));
    }

    public function testResetLinkFailureIsNotSwallowed(): void
    {
        $mailer = new RecordingMailer(new TransportException('smtp down'));

        $this->expectException(TransportException::class);

        (new MailPasswordResetEmailSender($mailer, new Logger('test'), 'https://app.example.test'))
            ->sendResetLink('owner@example.test', PasswordResetSecret::generate());
    }
}

final class RecordingMailer implements MailerInterface
{
    /** @var list<Email> */
    public array $sent = [];

    public function __construct(
        private readonly ?TransportException $failure = null,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
        \assert($message instanceof Email);
        $this->sent[] = $message;
    }
}
