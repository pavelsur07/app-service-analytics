<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\UserBuilder;
use App\Tests\Support\Fake\FakeCaptchaVerifier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * ADR-037 через HTTP: ответ на запрос не выдаёт существование аккаунта,
 * ссылка одноразовая, смена пароля завершает прежние сессии, а сессия
 * по ссылке не открывается. Проверки живут на границе — в контроллере,
 * лимитере и ContextListener, — и вызвать их иначе нечем.
 */
final class PasswordResetControllerTest extends WebTestCase
{
    private const string OLD_PASSWORD = 'old-password-1234';
    private const string NEW_PASSWORD = 'new-password-5678';

    private KernelBrowser $client;
    private FakeCaptchaVerifier $captchaVerifier;
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
        $this->captchaVerifier->reset();
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

    public function testResponseIsIdenticalForConfirmedUnconfirmedAndUnknownAddress(): void
    {
        $this->confirmedUser('known@example.test');
        UserBuilder::aUser()
            ->withEmail('waiting@example.test')
            ->unconfirmed()
            ->persistWith(new DoctrineUserRepository($this->entityManager()));

        $bodies = [];
        $mails = [];
        foreach (['known@example.test', 'waiting@example.test', 'unknown@example.test'] as $email) {
            $this->requestReset($email);
            self::assertResponseStatusCodeSame(202);
            $bodies[] = $this->client->getResponse()->getContent();
            self::assertEmailCount(1);
            $event = self::getMailerEvent();
            self::assertInstanceOf(MessageEvent::class, $event);
            self::assertEmailIsNotQueued($event);
            $mails[$email] = $this->lastEmailBody();
        }

        self::assertCount(1, array_unique($bodies), 'ответ не различает адреса');
        self::assertStringContainsString('/reset-password#token=', $mails['known@example.test']);
        self::assertStringContainsString('/confirm-email#token=', $mails['waiting@example.test'], 'неподтверждённому — подтверждение, не сброс');
        self::assertStringNotContainsString('token=', $mails['unknown@example.test']);
        self::assertSame(1, $this->resetTokenCount(), 'ссылка сброса выпущена только подтверждённому');
        self::assertCount(0, $this->asyncTransport()->getSent(), 'открытый токен не попал в очередь');
    }

    public function testLinkChangesPasswordOnceEndsOldSessionAndDoesNotOpenNewOne(): void
    {
        $this->confirmedUser('owner@example.test');
        $this->login('owner@example.test', self::OLD_PASSWORD);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();

        $this->requestReset('owner@example.test');
        $token = $this->tokenFromLastEmail();

        $this->confirmReset($token, self::NEW_PASSWORD);
        self::assertResponseIsSuccessful();
        self::assertSame('{"status":"password_changed"}', $this->client->getResponse()->getContent());
        self::assertEmailCount(1);
        self::assertStringContainsString('Пароль от вашего аккаунта Conwix только что изменён', $this->lastEmailBody());

        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401, 'сессия, открытая до сброса, завершена, а новая не открыта');

        $this->confirmReset($token, 'another-password-0000');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('reset_token_invalid', $this->errorCode());

        $this->login('owner@example.test', self::OLD_PASSWORD);
        self::assertResponseStatusCodeSame(401);
        $this->login('owner@example.test', self::NEW_PASSWORD);
        self::assertResponseIsSuccessful();
    }

    public function testMalformedAndUnknownTokensAnswerTheSame(): void
    {
        $this->confirmReset('not-a-token', self::NEW_PASSWORD);
        self::assertResponseStatusCodeSame(422);
        $malformed = $this->client->getResponse()->getContent();

        $this->confirmReset(str_repeat('a', 64), self::NEW_PASSWORD);
        self::assertResponseStatusCodeSame(422);

        self::assertSame($malformed, $this->client->getResponse()->getContent());
        self::assertEmailCount(0);
    }

    public function testShortPasswordIsRejectedWithoutConsumingLink(): void
    {
        $this->confirmedUser('owner@example.test');
        $this->requestReset('owner@example.test');
        $token = $this->tokenFromLastEmail();

        $this->confirmReset($token, 'short');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('password_too_short', $this->errorCode());

        $this->confirmReset($token, self::NEW_PASSWORD);
        self::assertResponseIsSuccessful();
    }

    public function testRequestRateLimitAndCaptchaAreCheckedBeforeAnyMail(): void
    {
        $this->captchaVerifier->reject();
        $this->requestReset('owner@example.test');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('captcha_invalid', $this->errorCode());
        self::assertEmailCount(0);

        $this->captchaVerifier->reset();
        // Лимит по адресу в test-окружении — 2 в час (rate_limiter.yaml);
        // отклонённая капчей попытка тоже учтена.
        $this->requestReset('owner@example.test');
        $this->requestReset('owner@example.test');
        self::assertResponseStatusCodeSame(429);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
        self::assertEmailCount(0);
        $this->assertRateLimitWarning('password_reset_request', ['email']);
    }

    /**
     * Отказ по лимиту — сигнал массовой атаки: без предупреждения в журнале
     * её видно только по жалобам и счёту SMTP-провайдера.
     *
     * @param list<string> $limits
     */
    private function assertRateLimitWarning(string $form, array $limits): void
    {
        $handler = self::getContainer()->get('monolog.handler.in_memory');
        self::assertInstanceOf(TestHandler::class, $handler);
        $warnings = array_values(array_filter(
            $handler->getRecords(),
            static fn ($record): bool => 'Public form rate limit exceeded' === $record->message,
        ));
        self::assertNotEmpty($warnings, 'отказ по лимиту обязан оставить предупреждение в журнале');
        $last = $warnings[\count($warnings) - 1];
        self::assertSame('WARNING', $last->level->getName());
        self::assertSame(['form' => $form, 'limits' => $limits], $last->context);
        self::assertStringNotContainsString('owner@example.test', json_encode($last->context, \JSON_THROW_ON_ERROR));
    }

    public function testConfirmRateLimitIsCheckedBeforeHashing(): void
    {
        // Лимит по IP в test-окружении — 3 в час.
        for ($i = 0; $i < 3; ++$i) {
            $this->confirmReset(str_repeat('b', 64), self::NEW_PASSWORD);
            self::assertResponseStatusCodeSame(422);
        }

        $this->confirmReset(str_repeat('b', 64), self::NEW_PASSWORD);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('password_reset_rate_limited', $this->errorCode());
        $this->assertRateLimitWarning('password_reset_confirm', ['ip']);
    }

    private function confirmedUser(string $email): void
    {
        $entityManager = $this->entityManager();
        /** @var CompanyRepository $companies */
        $companies = self::getContainer()->get(CompanyRepository::class);
        $users = new DoctrineUserRepository($entityManager);
        $company = CompanyBuilder::aCompany()->withName('Reset '.$email)->persistWith($companies);
        $user = UserBuilder::aUser()
            ->withEmail($email)
            ->withPasswordHash($this->hash(self::OLD_PASSWORD))
            ->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($company)
            ->withUser($user)
            ->persistWith($companies, $users, new DoctrineCompanyMemberRepository($entityManager));
    }

    private function requestReset(string $email): void
    {
        $this->client->request(
            'POST',
            '/api/auth/password-reset/request',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email, 'captchaToken' => 'captcha-token'], \JSON_THROW_ON_ERROR),
        );
    }

    private function confirmReset(string $token, string $password): void
    {
        $this->client->request(
            'POST',
            '/api/auth/password-reset/confirm',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['token' => $token, 'password' => $password], \JSON_THROW_ON_ERROR),
        );
    }

    private function login(string $email, string $password): void
    {
        $this->client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => $password], \JSON_THROW_ON_ERROR),
        );
    }

    private function tokenFromLastEmail(): string
    {
        if (1 !== preg_match('/#token=([0-9a-f]{64})/', $this->lastEmailBody(), $matches)) {
            self::fail('Письмо не содержит ссылку сброса.');
        }

        return $matches[1];
    }

    private function lastEmailBody(): string
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        return (string) $email->getTextBody();
    }

    private function errorCode(): mixed
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload['code'] ?? null;
    }

    private function resetTokenCount(): int
    {
        $count = $this->connection()->fetchOne('SELECT count(*) FROM password_reset_token');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function hash(string $plainPassword): string
    {
        /** @var PasswordHasherFactoryInterface $factory */
        $factory = self::getContainer()->get(PasswordHasherFactoryInterface::class);

        return $factory->getPasswordHasher(UserBuilder::aUser()->build())->hash($plainPassword);
    }

    private function asyncTransport(): InMemoryTransport
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
