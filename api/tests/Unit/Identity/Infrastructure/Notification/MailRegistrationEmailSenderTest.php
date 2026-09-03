<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Notification;

use App\Identity\Domain\ValueObject\EmailVerificationSecret;
use App\Identity\Infrastructure\Notification\MailRegistrationEmailSender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class MailRegistrationEmailSenderTest extends TestCase
{
    public function testConfirmationLinkKeepsTheEncodedBearerOnlyInTheFragment(): void
    {
        $mailer = new class implements MailerInterface {
            public ?RawMessage $message = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->message = $message;
            }
        };
        $sender = new MailRegistrationEmailSender($mailer, 'https://app.example.test/');

        $sender->sendConfirmation(
            'owner@example.test',
            EmailVerificationSecret::fromPlainText('one use/token#value'),
        );

        self::assertInstanceOf(Email::class, $mailer->message);
        $body = $mailer->message->getTextBody();
        self::assertIsString($body);
        self::assertStringContainsString(
            '/confirm-email#token=one%20use%2Ftoken%23value',
            $body,
        );
        self::assertStringNotContainsString('?token=', $body);
    }
}
