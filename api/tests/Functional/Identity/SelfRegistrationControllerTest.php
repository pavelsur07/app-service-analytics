<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\AuditAction;
use App\Identity\Domain\CaptchaUnavailable;
use App\Identity\Domain\CaptchaUnavailableReason;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\UserBuilder;
use App\Tests\Support\Fake\FakeCaptchaVerifier;
use App\Tests\Support\Probe\PasswordHasherProbe;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

final class SelfRegistrationControllerTest extends WebTestCase
{
    private const string GENERIC_MESSAGE = 'Если адрес указан верно, письмо с дальнейшими инструкциями уже отправлено.';
    private const string CAPTCHA_TOKEN = "  captcha-token\n";

    private KernelBrowser $client;
    private FakeCaptchaVerifier $captchaVerifier;
    private PasswordHasherProbe $passwordHasher;
    private ?CacheItemPoolInterface $rateLimiterPool = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->client->disableReboot();
        $rateLimiterPool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterPool);
        $this->rateLimiterPool = $rateLimiterPool;
        self::assertTrue($this->rateLimiterPool->clear());
        $captchaVerifier = self::getContainer()->get(FakeCaptchaVerifier::class);
        self::assertInstanceOf(FakeCaptchaVerifier::class, $captchaVerifier);
        $this->captchaVerifier = $captchaVerifier;
        $passwordHasher = self::getContainer()->get(PasswordHasherProbe::class);
        self::assertInstanceOf(PasswordHasherProbe::class, $passwordHasher);
        $this->passwordHasher = $passwordHasher;
        $this->captchaVerifier->reset();
        $this->passwordHasher->reset();
    }

    protected function tearDown(): void
    {
        try {
            if (null !== $this->rateLimiterPool) {
                self::assertTrue($this->rateLimiterPool->clear());
            }
        } finally {
            $this->rateLimiterPool = null;
            parent::tearDown();
        }
    }

    public function testFreeEmailCreatesWholeAccountAndSendsConfirmationWithoutQueueingPlainToken(): void
    {
        $client = $this->client;
        $before = $this->counts();

        $this->signUp($client, $this->validPayload('new-owner@example.test'));

        self::assertResponseStatusCodeSame(202);
        self::assertSame($this->expectedResponseBody(), $client->getResponse()->getContent());
        self::assertSame($this->incremented($before), $this->counts());
        self::assertSame(1, $this->captchaVerifier->calls);
        self::assertSame(self::CAPTCHA_TOKEN, $this->captchaVerifier->receivedToken);
        self::assertSame(1, $this->passwordHasher->hashCalls);

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
        $client = $this->client;
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
        $client = $this->client;
        $before = $this->counts();

        $this->signUp($client, $payload);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->counts());
        self::assertCount(0, $this->transport()->getSent());
        self::assertEmailCount(0);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidCaptchaTokens')]
    public function testInvalidCaptchaTokenIsRejectedBeforeProtectionHashingAndWrites(array $payload, string $remoteAddress): void
    {
        $client = $this->client;
        $before = $this->counts();

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->signUp($client, $payload, ['REMOTE_ADDR' => $remoteAddress]);

            self::assertResponseStatusCodeSame(422);
            self::assertSame(
                $this->expectedErrorBody(422, 'captcha_invalid', 'Проверьте CAPTCHA.'),
                $client->getResponse()->getContent(),
            );
        }

        self::assertSame(0, $this->captchaVerifier->calls);
        self::assertSame(0, $this->passwordHasher->hashCalls);
        self::assertSame($before, $this->counts());
        self::assertCount(0, $this->transport()->getSent());
        self::assertEmailCount(0);

        $validPayload = $payload;
        $validPayload['captchaToken'] = self::CAPTCHA_TOKEN;
        $this->signUp($client, $validPayload, ['REMOTE_ADDR' => $remoteAddress]);

        self::assertResponseStatusCodeSame(202);
        self::assertSame(1, $this->captchaVerifier->calls);
        self::assertSame(1, $this->passwordHasher->hashCalls);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidCaptchaTokens(): iterable
    {
        $valid = [
            'email' => 'missing-captcha@example.test',
            'password' => 'correct-horse-battery-staple',
            'companyName' => 'Ромашка',
            'legalConsent' => true,
        ];

        yield 'token is missing' => [$valid, '1.1.1.1'];
        yield 'token is not a string' => [array_replace($valid, ['email' => 'non-string-captcha@example.test', 'captchaToken' => 42]), '1.0.0.1'];
        yield 'token is empty' => [array_replace($valid, ['email' => 'empty-captcha@example.test', 'captchaToken' => '']), '9.9.9.9'];
        yield 'token is longer than 4096 characters' => [array_replace($valid, ['email' => 'long-captcha@example.test', 'captchaToken' => str_repeat('x', 4097)]), '8.8.4.4'];
    }

    public function testRejectedCaptchaReturnsValidationErrorBeforeHashingAndBusinessWrites(): void
    {
        $client = $this->client;
        $this->captchaVerifier->reject();
        $before = $this->counts();

        $this->signUp($client, $this->validPayload('captcha-rejected@example.test'), ['REMOTE_ADDR' => '8.8.8.8']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            $this->expectedErrorBody(422, 'captcha_invalid', 'Проверьте CAPTCHA.'),
            $client->getResponse()->getContent(),
        );
        self::assertSame(1, $this->captchaVerifier->calls);
        self::assertSame(self::CAPTCHA_TOKEN, $this->captchaVerifier->receivedToken);
        self::assertSame('8.8.8.8', $this->captchaVerifier->receivedClientIp);
        self::assertSame(0, $this->passwordHasher->hashCalls);
        self::assertSame($before, $this->counts());
        self::assertCount(0, $this->transport()->getSent());
        self::assertEmailCount(0);
    }

    public function testRejectedCaptchaConsumesBothDistributedLimits(): void
    {
        $this->withCleanRateLimiter(function (): void {
            $client = $this->client;
            $this->captchaVerifier->reject();
            $beforeRejected = $this->counts();

            $this->signUp($client, $this->validPayload('rejected-consumes@example.test'), ['REMOTE_ADDR' => '64.233.160.1']);

            self::assertResponseStatusCodeSame(422);
            self::assertSame($beforeRejected, $this->counts());
            self::assertSame(1, $this->captchaVerifier->calls);
            self::assertSame(0, $this->passwordHasher->hashCalls);
            self::assertEmailCount(0);

            $this->captchaVerifier->reset();
            $this->passwordHasher->reset();
            $this->signUp($client, $this->validPayload('REJECTED-CONSUMES@example.test'), ['REMOTE_ADDR' => '64.233.160.2']);
            self::assertResponseStatusCodeSame(202);
            $this->signUp($client, $this->validPayload('rejected-consumes@example.test'), ['REMOTE_ADDR' => '64.233.160.3']);
            $this->assertRateLimitedResponse($client);
            self::assertSame(1, $this->captchaVerifier->calls);
            self::assertSame(1, $this->passwordHasher->hashCalls);

            $this->captchaVerifier->reset();
            $this->passwordHasher->reset();
            $this->signUp($client, $this->validPayload('rejected-ip-probe-1@example.test'), ['REMOTE_ADDR' => '64.233.160.1']);
            self::assertResponseStatusCodeSame(202);
            $this->signUp($client, $this->validPayload('rejected-ip-probe-2@example.test'), ['REMOTE_ADDR' => '64.233.160.1']);
            self::assertResponseStatusCodeSame(202);
            $this->signUp($client, $this->validPayload('rejected-ip-probe-3@example.test'), ['REMOTE_ADDR' => '64.233.160.1']);
            $this->assertRateLimitedResponse($client);
            self::assertSame(2, $this->captchaVerifier->calls);
            self::assertSame(2, $this->passwordHasher->hashCalls);
        });
    }

    public function testUnavailableCaptchaReturnsRetryableServiceErrorBeforeHashingAndBusinessWrites(): void
    {
        $client = $this->client;
        $this->captchaVerifier->becomeUnavailable(new CaptchaUnavailable(CaptchaUnavailableReason::Transport));
        $before = $this->counts();

        $this->signUp($client, $this->validPayload('captcha-unavailable@example.test'), ['REMOTE_ADDR' => '4.2.2.2']);

        self::assertResponseStatusCodeSame(503);
        self::assertSame(
            $this->expectedErrorBody(503, 'captcha_unavailable', 'Проверка CAPTCHA временно недоступна. Попробуйте позже.'),
            $client->getResponse()->getContent(),
        );
        self::assertSame('30', $client->getResponse()->headers->get('Retry-After'));
        self::assertSame(1, $this->captchaVerifier->calls);
        self::assertSame(0, $this->passwordHasher->hashCalls);
        self::assertSame($before, $this->counts());
        self::assertCount(0, $this->transport()->getSent());
        self::assertEmailCount(0);
    }

    public function testEmailRateLimitNormalizesCaseAndDoesNotAffectAnUnrelatedPair(): void
    {
        $this->withCleanRateLimiter(function (): void {
            $client = $this->client;

            $this->signUp($client, $this->validPayload('Rate-Limited@Example.Test'), ['REMOTE_ADDR' => '31.13.64.1']);
            self::assertResponseStatusCodeSame(202);
            $this->signUp($client, $this->validPayload('rate-limited@example.test'), ['REMOTE_ADDR' => '31.13.64.2']);
            self::assertResponseStatusCodeSame(202);

            $beforeRejected = $this->counts();
            self::assertSame(2, $this->captchaVerifier->calls);
            self::assertSame(2, $this->passwordHasher->hashCalls);
            self::assertEmailCount(1);

            $this->signUp($client, $this->validPayload('RATE-LIMITED@example.test'), ['REMOTE_ADDR' => '31.13.64.3']);

            $this->assertRateLimitedResponse($client);
            self::assertSame(2, $this->captchaVerifier->calls);
            self::assertSame(2, $this->passwordHasher->hashCalls);
            self::assertSame($beforeRejected, $this->counts());
            self::assertCount(0, $this->transport()->getSent());
            self::assertEmailCount(0);

            $this->signUp($client, $this->validPayload('unrelated-rate-limit@example.test'), ['REMOTE_ADDR' => '31.13.64.4']);

            self::assertResponseStatusCodeSame(202);
            self::assertSame(3, $this->captchaVerifier->calls);
            self::assertSame(3, $this->passwordHasher->hashCalls);
            self::assertSame($this->incremented($beforeRejected), $this->counts());
            self::assertEmailCount(1);
        });
    }

    public function testIpRateLimitRejectsTheFourthDifferentEmailWithoutBusinessEffects(): void
    {
        $this->withCleanRateLimiter(function (): void {
            $client = $this->client;
            $remoteAddress = '93.184.216.34';

            for ($attempt = 1; $attempt <= 3; ++$attempt) {
                $this->signUp($client, $this->validPayload("ip-rate-{$attempt}@example.test"), ['REMOTE_ADDR' => $remoteAddress]);
                self::assertResponseStatusCodeSame(202);
            }

            $beforeRejected = $this->counts();
            self::assertSame(3, $this->captchaVerifier->calls);
            self::assertSame(3, $this->passwordHasher->hashCalls);
            self::assertEmailCount(1);

            $this->signUp($client, $this->validPayload('ip-rate-4@example.test'), ['REMOTE_ADDR' => $remoteAddress]);

            $this->assertRateLimitedResponse($client);
            self::assertSame(3, $this->captchaVerifier->calls);
            self::assertSame(3, $this->passwordHasher->hashCalls);
            self::assertSame($beforeRejected, $this->counts());
            self::assertCount(0, $this->transport()->getSent());
            self::assertEmailCount(0);
        });
    }

    public function testTrustedPrivateProxyPassesForwardedClientIpToCaptcha(): void
    {
        $client = $this->client;

        $this->signUp(
            $client,
            $this->validPayload('trusted-proxy@example.test'),
            [
                'REMOTE_ADDR' => '10.20.30.40',
                'HTTP_X_FORWARDED_FOR' => '45.67.89.10',
            ],
        );

        self::assertResponseStatusCodeSame(202);
        self::assertSame(1, $this->captchaVerifier->calls);
        self::assertSame('45.67.89.10', $this->captchaVerifier->receivedClientIp);
    }

    public function testUntrustedPublicRemoteAddressIgnoresSpoofedForwardedClientIp(): void
    {
        $client = $this->client;

        $this->signUp(
            $client,
            $this->validPayload('untrusted-proxy@example.test'),
            [
                'REMOTE_ADDR' => '8.26.56.26',
                'HTTP_X_FORWARDED_FOR' => '45.67.89.11',
            ],
        );

        self::assertResponseStatusCodeSame(202);
        self::assertSame(1, $this->captchaVerifier->calls);
        self::assertSame('8.26.56.26', $this->captchaVerifier->receivedClientIp);
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
            'captchaToken' => self::CAPTCHA_TOKEN,
        ];
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $server
     */
    private function signUp(KernelBrowser $client, array $payload, array $server = []): void
    {
        $client->request(
            'POST',
            '/api/auth/sign-up',
            server: array_replace(['CONTENT_TYPE' => 'application/json'], $server),
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    private function expectedResponseBody(): string
    {
        return json_encode(['message' => self::GENERIC_MESSAGE], \JSON_THROW_ON_ERROR);
    }

    private function expectedErrorBody(int $status, string $code, string $message): string
    {
        return json_encode(['status' => $status, 'code' => $code, 'message' => $message], \JSON_THROW_ON_ERROR);
    }

    private function assertRateLimitedResponse(KernelBrowser $client): void
    {
        self::assertResponseStatusCodeSame(429);
        self::assertSame(
            $this->expectedErrorBody(429, 'registration_rate_limited', 'Слишком много попыток регистрации. Попробуйте позже.'),
            $client->getResponse()->getContent(),
        );
        $retryAfter = $client->getResponse()->headers->get('Retry-After');
        self::assertNotNull($retryAfter);
        self::assertMatchesRegularExpression('/^\d+$/', $retryAfter);
        self::assertGreaterThanOrEqual(0, (int) $retryAfter);
    }

    private function withCleanRateLimiter(\Closure $scenario): void
    {
        $pool = $this->rateLimiterPool;
        self::assertNotNull($pool);
        self::assertTrue($pool->clear());

        try {
            $scenario();
        } finally {
            self::assertTrue($pool->clear());
        }
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
