<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Predis\Client as PredisClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * ADR-007: одинаковая ошибка на "нет пользователя"/"неверный пароль",
 * сессия инвалидируется при выходе, /api/auth/me — источник правды
 * для email + списка компаний. DoctrineUserRepository/
 * DoctrineCompanyMemberRepository строятся напрямую по той же причине,
 * что в PR1 (см. Integration/Identity/DoctrineUserRepositoryTest) — оба
 * ещё не потребляются ничем, кроме UserProvider (для UserRepository —
 * потребляется, но alias интерфейса не проверялся отдельно; надёжнее
 * строить репозиторий явно, не полагаясь на то, останется ли он публичным
 * в конкретной сборке контейнера).
 */
final class AuthControllerTest extends WebTestCase
{
    public function testLoginWithCorrectPasswordSucceedsAndMeReturnsCompanies(): void
    {
        $client = static::createClient();
        [$companies, $users, $companyMembers] = $this->repositories();

        $company = CompanyBuilder::aCompany()->withName('Acme LLC')->persistWith($companies);
        // Компания, к которой пользователь не имеет отношения — доказывает
        // изоляцию, а не только то, что своя компания попадает в ответ
        // (ADR-005, обязательное покрытие: изоляция данных между
        // компаниями).
        CompanyBuilder::aCompany()->withName('Other Company')->persistWith($companies);
        $user = UserBuilder::aUser()
            ->withEmail('owner@example.com')
            ->withPasswordHash($this->hash('correct-horse-battery-staple'))
            ->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith($companies, $users, $companyMembers);

        $this->login($client, 'owner@example.com', 'correct-horse-battery-staple');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
        $payload = $this->decodeResponse($client);

        self::assertSame('owner@example.com', $payload['email']);
        self::assertIsArray($payload['companies']);
        self::assertCount(1, $payload['companies'], 'ответ не должен включать компании, где пользователь не участник');
        self::assertIsArray($payload['companies'][0]);
        self::assertSame('Acme LLC', $payload['companies'][0]['name']);
    }

    public function testWrongPasswordAndUnknownEmailReturnTheSameError(): void
    {
        $client = static::createClient();
        [, $users] = $this->repositories();
        UserBuilder::aUser()->withEmail('real@example.com')->withPasswordHash($this->hash('real-password'))->persistWith($users);

        $this->login($client, 'real@example.com', 'wrong-password');
        self::assertResponseStatusCodeSame(401);
        $wrongPasswordPayload = $this->decodeResponse($client);

        // Один и тот же клиент — неудачный вход не заводит сессию, второй
        // запрос на нём независим от первого.
        $this->login($client, 'nobody@example.com', 'wrong-password');
        self::assertResponseStatusCodeSame(401);
        $unknownEmailPayload = $this->decodeResponse($client);

        self::assertSame($wrongPasswordPayload['code'], $unknownEmailPayload['code']);
        self::assertSame($wrongPasswordPayload['message'], $unknownEmailPayload['message']);
    }

    public function testMeWithoutSessionReturns401(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/auth/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testLogoutInvalidatesSession(): void
    {
        $client = static::createClient();
        [, $users] = $this->repositories();
        UserBuilder::aUser()->withEmail('logout@example.com')->withPasswordHash($this->hash('some-password'))->persistWith($users);

        $this->login($client, 'logout@example.com', 'some-password');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/auth/logout');

        $client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testExceedingLoginAttemptsIsThrottled(): void
    {
        // login_throttling хранит счётчик в Redis (cache.rate_limiter),
        // который DAMA не откатывает между тестами, в отличие от Postgres —
        // без сброса тест зависел бы от истории прошлых прогонов.
        $this->redisConnection()->flushdb();

        $client = static::createClient();
        [, $users] = $this->repositories();
        UserBuilder::aUser()->withEmail('throttle@example.com')->withPasswordHash($this->hash('correct-password'))->persistWith($users);

        for ($i = 0; $i < 5; ++$i) {
            $this->login($client, 'throttle@example.com', 'wrong-password');
            self::assertResponseStatusCodeSame(401);
        }

        // 6-я попытка — даже с верным паролем — блокируется лимитером
        // (ADR-007: по паре email+IP), а не проходит как обычный вход.
        $this->login($client, 'throttle@example.com', 'correct-password');
        self::assertResponseStatusCodeSame(401);
        $payload = $this->decodeResponse($client);
        self::assertIsString($payload['message']);
        self::assertStringContainsString('Too many', $payload['message']);
    }

    private function login(KernelBrowser $client, string $email, string $password): void
    {
        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => $password], \JSON_THROW_ON_ERROR),
        );
    }

    private function hash(string $plainPassword): string
    {
        // PasswordHasherFactoryInterface, не UserPasswordHasherInterface:
        // последний ничем не потребляется до PR3 (консольная команда)
        // и вырезается компилятором как неиспользуемый — та же причина,
        // что у DoctrineUserRepository в PR1. Фабрику потребляет штатный
        // CheckCredentialsListener, поэтому она в контейнере всегда.
        /** @var PasswordHasherFactoryInterface $factory */
        $factory = static::getContainer()->get(PasswordHasherFactoryInterface::class);

        return $factory->getPasswordHasher(UserBuilder::aUser()->build())->hash($plainPassword);
    }

    private function redisConnection(): PredisClient
    {
        // Не через контейнер: в test-окружении сессии идут через
        // session.storage.factory.mock_file (framework.yaml, when@test),
        // handler_id/redis.connection этим путём не задействуются и могут
        // быть вырезаны компилятором как неиспользуемые именно в этом
        // окружении — тот же класс проблемы, что и в PR1.
        $redisUrl = $_ENV['REDIS_URL'] ?? null;
        self::assertIsString($redisUrl, 'REDIS_URL must be set in the test environment.');

        return new PredisClient($redisUrl);
    }

    /**
     * @return array{0: CompanyRepository, 1: DoctrineUserRepository, 2: DoctrineCompanyMemberRepository}
     */
    private function repositories(): array
    {
        $entityManager = $this->entityManager();

        /** @var CompanyRepository $companies */
        $companies = static::getContainer()->get(CompanyRepository::class);

        return [$companies, new DoctrineUserRepository($entityManager), new DoctrineCompanyMemberRepository($entityManager)];
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $payload;
    }
}
