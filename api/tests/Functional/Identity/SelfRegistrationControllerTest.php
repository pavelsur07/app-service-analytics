<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\AuditAction;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

final class SelfRegistrationControllerTest extends WebTestCase
{
    private const string GENERIC_MESSAGE = 'Если адрес указан верно, письмо с дальнейшими инструкциями уже отправлено.';

    public function testFreeEmailCreatesWholeAccountAndSendsConfirmationWithoutQueueingPlainToken(): void
    {
        $client = static::createClient();
        $before = $this->counts();

        $this->signUp($client, $this->validPayload('new-owner@example.test'));

        self::assertResponseStatusCodeSame(202);
        self::assertSame($this->expectedResponseBody(), $client->getResponse()->getContent());
        self::assertSame($this->incremented($before), $this->counts());

        $account = $this->connection()->fetchAssociative(<<<'SQL'
            SELECT u.id AS user_id,
                   u.email_confirmed_at,
                   u.legal_consent_at,
                   u.legal_documents_version,
                   c.id AS company_id,
                   c.name,
                   cm.role,
                   t.token_hash,
                   t.consumed_at,
                   a.actor_user_id,
                   a.actor_admin_id,
                   a.action
            FROM "user" u
            INNER JOIN company_member cm ON cm.user_id = u.id
            INNER JOIN company c ON c.id = cm.company_id
            INNER JOIN email_verification_token t ON t.user_id = u.id
            INNER JOIN audit_record a ON a.company_id = c.id
            WHERE u.email = :email AND a.action = :action
            SQL,
            ['email' => 'new-owner@example.test', 'action' => AuditAction::CompanyRegistered],
        );
        self::assertIsArray($account);
        self::assertNull($account['email_confirmed_at']);
        self::assertNotNull($account['legal_consent_at']);
        self::assertSame('2026-09-02', $account['legal_documents_version']);
        self::assertSame('Ромашка ООО', $account['name']);
        self::assertSame('owner', $account['role']);
        self::assertNull($account['consumed_at']);
        self::assertSame($account['user_id'], $account['actor_user_id']);
        self::assertNull($account['actor_admin_id']);

        self::assertCount(0, $this->transport()->getSent());
        self::assertEmailCount(1);
        $event = self::getMailerEvent();
        self::assertInstanceOf(MessageEvent::class, $event);
        self::assertEmailIsNotQueued($event);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('new-owner@example.test', $email->getTo()[0]->getAddress());
        self::assertSame('Conwix: подтвердите адрес электронной почты', $email->getSubject());
        $text = $email->getTextBody();
        self::assertIsString($text);
        self::assertSame(1, preg_match('~/confirm-email\?token=([0-9a-f]{64})~', $text, $matches));
        $plainTextToken = $matches[1] ?? null;
        self::assertIsString($plainTextToken);
        self::assertSame($account['token_hash'], hash('sha256', $plainTextToken));
    }

    public function testTakenEmailReturnsSamePublicResponseWithoutCreatingRows(): void
    {
        $client = static::createClient();
        UserBuilder::aUser()
            ->withEmail('existing-owner@example.test')
            ->persistWith(new DoctrineUserRepository($this->entityManager()));
        $before = $this->counts();

        $this->signUp($client, $this->validPayload('existing-owner@example.test'));

        self::assertResponseStatusCodeSame(202);
        self::assertSame($this->expectedResponseBody(), $client->getResponse()->getContent());
        self::assertSame($before, $this->counts());

        self::assertCount(0, $this->transport()->getSent());
        self::assertEmailCount(1);
        $event = self::getMailerEvent();
        self::assertInstanceOf(MessageEvent::class, $event);
        self::assertEmailIsNotQueued($event);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('existing-owner@example.test', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('уже существует', (string) $email->getTextBody());
        self::assertStringNotContainsString('token=', (string) $email->getTextBody());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadDoesNotHashOrWriteOrQueueMail(array $payload): void
    {
        $client = static::createClient();
        $before = $this->counts();

        $this->signUp($client, $payload);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->counts());
        self::assertCount(0, $this->transport()->getSent());
        self::assertEmailCount(0);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'consent is false' => [[
            'email' => 'owner@example.test',
            'password' => 'correct-horse-battery-staple',
            'companyName' => 'Ромашка',
            'legalConsent' => false,
        ]];
        yield 'consent is missing' => [[
            'email' => 'owner@example.test',
            'password' => 'correct-horse-battery-staple',
            'companyName' => 'Ромашка',
        ]];
        yield 'email is invalid' => [[
            'email' => 'not-an-email',
            'password' => 'correct-horse-battery-staple',
            'companyName' => 'Ромашка',
            'legalConsent' => true,
        ]];
        yield 'company is blank' => [[
            'email' => 'owner@example.test',
            'password' => 'correct-horse-battery-staple',
            'companyName' => '   ',
            'legalConsent' => true,
        ]];
        yield 'password is shorter than twelve characters' => [[
            'email' => 'owner@example.test',
            'password' => 'short',
            'companyName' => 'Ромашка',
            'legalConsent' => true,
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(string $email): array
    {
        return [
            'email' => $email,
            'password' => 'correct-horse-battery-staple',
            'companyName' => 'Ромашка ООО',
            'legalConsent' => true,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signUp(KernelBrowser $client, array $payload): void
    {
        $client->request(
            'POST',
            '/api/auth/sign-up',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    private function expectedResponseBody(): string
    {
        return json_encode(['message' => self::GENERIC_MESSAGE], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{company: int, user: int, membership: int, token: int, audit: int}
     */
    private function counts(): array
    {
        return [
            'company' => $this->countRows('SELECT count(*) FROM company'),
            'user' => $this->countRows('SELECT count(*) FROM "user"'),
            'membership' => $this->countRows('SELECT count(*) FROM company_member'),
            'token' => $this->countRows('SELECT count(*) FROM email_verification_token'),
            'audit' => $this->countRows('SELECT count(*) FROM audit_record'),
        ];
    }

    private function countRows(string $sql): int
    {
        $count = $this->connection()->fetchOne($sql);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * @param array{company: int, user: int, membership: int, token: int, audit: int} $counts
     *
     * @return array{company: int, user: int, membership: int, token: int, audit: int}
     */
    private function incremented(array $counts): array
    {
        return array_map(static fn (int $count): int => $count + 1, $counts);
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
