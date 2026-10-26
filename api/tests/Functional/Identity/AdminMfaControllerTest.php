<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\Administrator;
use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Tests\Support\Builder\AdministratorBuilder;
use App\Tests\Support\Fake\MutableMfaClock;
use App\Tests\Support\SessionWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final class AdminMfaControllerTest extends SessionWebTestCase
{
    private KernelBrowser $client;
    private ?CacheItemPoolInterface $limiterPool = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $this->limiterPool = $pool;
        self::assertTrue($pool->clear());
    }

    protected function tearDown(): void
    {
        try {
            $this->limiterPool?->clear();
        } finally {
            $this->limiterPool = null;
            parent::tearDown();
        }
    }

    public function testPasswordLoginCreatesOnlyPendingMfaSession(): void
    {
        $client = $this->client;
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $factory = self::getContainer()->get(PasswordHasherFactoryInterface::class);
        self::assertInstanceOf(PasswordHasherFactoryInterface::class, $factory);
        $admin = AdministratorBuilder::aBootstrapSuperAdmin()->withEmail('mfa@example.test')
            ->withPasswordHash($factory->getPasswordHasher(Administrator::class)->hash('mfa-password-1234'))
            ->persistWith(new DoctrineAdministratorRepository($entityManager));

        $client->jsonRequest('POST', '/api/admin/auth/login', ['email' => $admin->email(), 'password' => 'mfa-password-1234']);
        self::assertResponseStatusCodeSame(202);
        $client->request('GET', '/api/admin/companies');
        self::assertResponseStatusCodeSame(401);
    }

    public function testEnrollmentCompletesLoginAndRecoveryCodeIsSingleUse(): void
    {
        $client = $this->client;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
        $factory = self::getContainer()->get(PasswordHasherFactoryInterface::class);
        self::assertInstanceOf(PasswordHasherFactoryInterface::class, $factory);
        $admin = AdministratorBuilder::aBootstrapSuperAdmin()->withEmail('enroll@example.test')
            ->withPasswordHash($factory->getPasswordHasher(Administrator::class)->hash('mfa-password-1234'))
            ->persistWith(new DoctrineAdministratorRepository($em));
        $credentials = ['email' => $admin->email(), 'password' => 'mfa-password-1234'];
        $client->jsonRequest('POST', '/api/admin/auth/login', $credentials);
        self::assertResponseStatusCodeSame(202);
        $client->request('GET', '/api/admin/auth/mfa/status');
        self::assertResponseIsSuccessful();
        $client->jsonRequest('POST', '/api/admin/auth/mfa/enroll');
        self::assertResponseIsSuccessful();
        $enrollment = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($enrollment);
        self::assertIsString($enrollment['secret']);
        if ('' === $enrollment['secret']) {
            self::fail('Enrollment must return a non-empty secret.');
        }
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $timestamp = $clock->now()->getTimestamp();
        if ($timestamp < 0) {
            self::fail('A non-negative timestamp is required.');
        }
        $code = TOTP::createFromSecret($enrollment['secret'])->at($timestamp);
        $client->jsonRequest('POST', '/api/admin/auth/mfa/confirm', ['code' => $code]);
        self::assertResponseIsSuccessful();
        $completed = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($completed);
        self::assertIsArray($completed['recoveryCodes']);
        self::assertCount(10, $completed['recoveryCodes']);
        $recovery = $completed['recoveryCodes'][0];
        self::assertIsString($recovery);
        $client->request('GET', '/api/admin/auth/me');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/admin/auth/logout');
        $client->jsonRequest('POST', '/api/admin/auth/login', $credentials);
        self::assertResponseStatusCodeSame(202);
        $client->jsonRequest('POST', '/api/admin/auth/mfa/verify', ['code' => $recovery]);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/admin/auth/me');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/admin/auth/logout');
        $client->jsonRequest('POST', '/api/admin/auth/login', $credentials);
        $client->jsonRequest('POST', '/api/admin/auth/mfa/verify', ['code' => $recovery]);
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', '/api/admin/auth/me');
        self::assertResponseStatusCodeSame(401);
        $pool->clear();
    }

    public function testChallengeExpiresAtFiveMinutesAndPendingSessionCannotRegenerateCodes(): void
    {
        $admin = $this->administrator('expiry@example.test');
        $this->client->jsonRequest('POST', '/api/admin/auth/login', ['email' => $admin->email(), 'password' => 'mfa-password-1234']);
        self::assertResponseStatusCodeSame(202);
        $this->client->jsonRequest('POST', '/api/admin/auth/mfa/recovery-codes', ['code' => '000000']);
        self::assertResponseStatusCodeSame(401);
        $clock = self::getContainer()->get(MutableMfaClock::class);
        self::assertInstanceOf(MutableMfaClock::class, $clock);
        $clock->advance(300);
        $this->client->request('GET', '/api/admin/auth/mfa/status');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/api/admin/companies');
        self::assertResponseStatusCodeSame(401);
    }

    public function testNewPasswordChallengeDoesNotResetMfaRateLimit(): void
    {
        $admin = $this->administrator('limits@example.test');
        for ($attempt = 1; $attempt <= 6; ++$attempt) {
            $this->client->jsonRequest('POST', '/api/admin/auth/login', ['email' => $admin->email(), 'password' => 'mfa-password-1234']);
            self::assertResponseStatusCodeSame(202);
            $this->client->request('POST', '/api/admin/auth/mfa/enroll');
            self::assertResponseStatusCodeSame($attempt <= 5 ? 200 : 429);
        }
    }

    public function testFactorResetInvalidatesAnAlreadyVerifiedSession(): void
    {
        $admin = $this->administrator('reset-mfa@example.test');
        $this->authenticateAdministrator($this->client, $admin);
        $this->client->request('GET', '/api/admin/auth/me');
        self::assertResponseIsSuccessful();
        $repository = self::getContainer()->get(\App\Identity\Domain\AdminMfaRepository::class);
        self::assertInstanceOf(\App\Identity\Domain\AdminMfaRepository::class, $repository);
        $repository->reset($admin->id(), $admin->id(), 'verified-ticket-123', new \DateTimeImmutable());
        $this->client->request('GET', '/api/admin/auth/me');
        self::assertResponseStatusCodeSame(401);
    }

    private function administrator(string $email): Administrator
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $factory = self::getContainer()->get(PasswordHasherFactoryInterface::class);
        self::assertInstanceOf(PasswordHasherFactoryInterface::class, $factory);

        return AdministratorBuilder::aBootstrapSuperAdmin()->withEmail($email)
            ->withPasswordHash($factory->getPasswordHasher(Administrator::class)->hash('mfa-password-1234'))
            ->persistWith(new DoctrineAdministratorRepository($em));
    }

    public function testLegacyAuthenticatedSessionCannotCreateAdministratorWithoutMfa(): void
    {
        $client = $this->client;
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $admin = AdministratorBuilder::aBootstrapSuperAdmin()->persistWith(new DoctrineAdministratorRepository($entityManager));
        $client->loginUser($admin, 'admin');
        $client->jsonRequest('POST', '/api/admin/administrators', [
            'email' => 'blocked-without-mfa@example.test', 'password' => 'long-password-1234',
        ]);
        self::assertResponseStatusCodeSame(401);
        self::assertFalse($entityManager->getConnection()->fetchOne(
            'SELECT id FROM administrator WHERE email = :email', ['email' => 'blocked-without-mfa@example.test'],
        ));
    }
}
