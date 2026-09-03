<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

final class ResendEmailVerificationControllerTest extends WebTestCase
{
    private const string GENERIC_MESSAGE = 'Если адрес указан верно, письмо с дальнейшими инструкциями уже отправлено.';

    public function testTwoResendsAppendTwoTokensAndSendTwoConfirmationEmailsWithoutQueueingPlainTokens(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $user = UserBuilder::aUser()
            ->withEmail('waiting@example.test')
            ->unconfirmed()
            ->persistWith(new DoctrineUserRepository($this->entityManager()));
        $this->resend($client, 'waiting@example.test');
        self::assertResponseStatusCodeSame(202);
        self::assertSame($this->expectedResponseBody(), $client->getResponse()->getContent());
        $firstRows = $this->tokenRows($user->id()->toRfc4122());
        self::assertCount(1, $firstRows);
        self::assertCount(0, $this->transport()->getSent());
        self::assertEmailCount(1);
        $firstEvent = self::getMailerEvent(0);
        self::assertInstanceOf(MessageEvent::class, $firstEvent);
        self::assertEmailIsNotQueued($firstEvent);
        $firstEmail = self::getMailerMessage(0);
        self::assertInstanceOf(Email::class, $firstEmail);
        self::assertStringContainsString('/confirm-email#token=', (string) $firstEmail->getTextBody());
        self::assertStringNotContainsString('?token=', (string) $firstEmail->getTextBody());

        $this->resend($client, 'waiting@example.test');
        self::assertResponseStatusCodeSame(202);
        self::assertSame($this->expectedResponseBody(), $client->getResponse()->getContent());
        $rows = $this->tokenRows($user->id()->toRfc4122());

        self::assertCount(2, $rows);
        self::assertNotSame($rows[0]['token_hash'], $rows[1]['token_hash']);
        self::assertSame($firstRows[0], $rows[0], 'повторная отправка не меняет первую append-only строку');
        self::assertNull($rows[0]['consumed_at']);
        self::assertNull($rows[1]['consumed_at']);
        self::assertCount(0, $this->transport()->getSent());
        self::assertEmailCount(1);
        $secondEvent = self::getMailerEvent(0);
        self::assertInstanceOf(MessageEvent::class, $secondEvent);
        self::assertEmailIsNotQueued($secondEvent);
        $secondEmail = self::getMailerMessage(0);
        self::assertInstanceOf(Email::class, $secondEmail);
        self::assertStringContainsString('/confirm-email#token=', (string) $secondEmail->getTextBody());
        self::assertStringNotContainsString('?token=', (string) $secondEmail->getTextBody());
    }

    public function testUnknownEmailHasSameResponseAndSynchronousMailShapeWithoutToken(): void
    {
        $client = static::createClient();
        $this->resend($client, 'unknown@example.test');

        self::assertResponseStatusCodeSame(202);
        self::assertSame($this->expectedResponseBody(), $client->getResponse()->getContent());
        self::assertSame(0, $this->tokenCount());
        self::assertCount(0, $this->transport()->getSent());
        self::assertEmailCount(1);
        $event = self::getMailerEvent();
        self::assertInstanceOf(MessageEvent::class, $event);
        self::assertEmailIsNotQueued($event);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('unknown@example.test', $email->getTo()[0]->getAddress());
        self::assertStringNotContainsString('token=', (string) $email->getTextBody());
    }

    public function testConfirmedEmailHasSameResponseAndQueuesReminderWithoutToken(): void
    {
        $client = static::createClient();
        UserBuilder::aUser()
            ->withEmail('confirmed@example.test')
            ->persistWith(new DoctrineUserRepository($this->entityManager()));
        $this->resend($client, 'confirmed@example.test');

        self::assertResponseStatusCodeSame(202);
        self::assertSame($this->expectedResponseBody(), $client->getResponse()->getContent());
        self::assertSame(0, $this->tokenCount());
        self::assertCount(0, $this->transport()->getSent());
        self::assertEmailCount(1);
        $event = self::getMailerEvent();
        self::assertInstanceOf(MessageEvent::class, $event);
        self::assertEmailIsNotQueued($event);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertStringContainsString('уже существует', (string) $email->getTextBody());
        self::assertStringNotContainsString('token=', (string) $email->getTextBody());
    }

    public function testInvalidEmailIsRejectedWithoutTokenOrMail(): void
    {
        $client = static::createClient();
        $this->resend($client, 'not-an-email');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->tokenCount());
        self::assertCount(0, $this->transport()->getSent());
        self::assertEmailCount(0);
    }

    private function resend(KernelBrowser $client, string $email): void
    {
        $client->request(
            'POST',
            '/api/auth/email-verification/resend',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email], \JSON_THROW_ON_ERROR),
        );
    }

    private function expectedResponseBody(): string
    {
        return json_encode(['message' => self::GENERIC_MESSAGE], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tokenRows(string $userId): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT id, user_id, token_hash, issued_at, expires_at, consumed_at FROM email_verification_token WHERE user_id = :user ORDER BY issued_at, id',
            ['user' => $userId],
        );
    }

    private function tokenCount(): int
    {
        $count = $this->connection()->fetchOne('SELECT count(*) FROM email_verification_token');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_ingestion');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
