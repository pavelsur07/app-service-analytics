<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\ExtensionTokenSecret;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineExtensionTokenRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\ExtensionTokenBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-010: выпуск идёт под сессией, проверка — под токеном на отдельном
 * firewall. Обязательное покрытие ADR-005 — изоляция данных между
 * компаниями: токен, выпущенный на одну компанию, не даёт доступа
 * к другой, даже если пользователь состоит в обеих.
 */
final class ExtensionTokenControllerTest extends WebTestCase
{
    private const string PASSWORD = 'correct-horse-battery-staple';

    public function testIssuedTokenIdentifiesItsOwnCompanyOnly(): void
    {
        $client = static::createClient();
        [$companies, $users, $companyMembers] = $this->repositories();

        // Пользователь состоит в обеих — если бы токен опознавал человека,
        // а не пару «человек + компания», ответ был бы неоднозначным.
        $ours = CompanyBuilder::aCompany()->withName('Acme LLC')->persistWith($companies);
        $theirs = CompanyBuilder::aCompany()->withName('Second Company')->persistWith($companies);
        $user = $this->persistUser($users, 'owner@example.com');
        CompanyMemberBuilder::aCompanyMember()->withCompany($ours)->withUser($user)->persistWith($companies, $users, $companyMembers);
        CompanyMemberBuilder::aCompanyMember()->withCompany($theirs)->withUser($user)->persistWith($companies, $users, $companyMembers);

        $this->login($client, 'owner@example.com');
        $issued = $this->issueToken($client, $ours);

        self::assertIsString($issued['token']);
        self::assertStringStartsWith(ExtensionTokenSecret::PREFIX, $issued['token']);

        $payload = $this->extensionMe($client, $issued['token']);

        self::assertSame('owner@example.com', $payload['email']);
        self::assertIsArray($payload['company']);
        self::assertSame($ours->id()->toRfc4122(), $payload['company']['id']);
        self::assertSame('Acme LLC', $payload['company']['name'], 'токен обязан отдавать компанию выпуска, не любую доступную пользователю');
    }

    public function testSecretIsReturnedOnceAndNotStored(): void
    {
        $client = static::createClient();
        [$companies, $users, $companyMembers] = $this->repositories();

        $company = $this->companyWithMember($companies, $users, $companyMembers, 'owner@example.com');
        $this->login($client, 'owner@example.com');

        $first = $this->issueToken($client, $company);
        $second = $this->issueToken($client, $company);

        self::assertIsString($first['token']);
        self::assertIsString($second['token']);
        // Повторный запрос выпускает новый токен, а не показывает старый:
        // в базе лежит только хэш, восстановить секрет неоткуда.
        self::assertNotSame($first['token'], $second['token']);
        self::assertNotSame($first['id'], $second['id']);
    }

    public function testIssueForForeignCompanyIsForbidden(): void
    {
        $client = static::createClient();
        [$companies, $users, $companyMembers] = $this->repositories();

        $this->companyWithMember($companies, $users, $companyMembers, 'owner@example.com');
        $foreign = CompanyBuilder::aCompany()->withName('Foreign')->persistWith($companies);

        $this->login($client, 'owner@example.com');
        $client->request('POST', '/api/companies/'.$foreign->id()->toRfc4122().'/extension-tokens');

        self::assertResponseStatusCodeSame(403);
    }

    public function testIssueWithoutSessionIsUnauthorized(): void
    {
        $client = static::createClient();
        [$companies] = $this->repositories();

        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $client->request('POST', '/api/companies/'.$company->id()->toRfc4122().'/extension-tokens');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRevokedTokenIsRejected(): void
    {
        $client = static::createClient();
        [$companies, $users, $companyMembers] = $this->repositories();

        $company = $this->companyWithMember($companies, $users, $companyMembers, 'owner@example.com');
        $this->login($client, 'owner@example.com');
        $issued = $this->issueToken($client, $company);
        self::assertIsString($issued['token']);
        self::assertIsString($issued['id']);

        $this->extensionMe($client, $issued['token']);
        self::assertResponseIsSuccessful();

        $client->request('DELETE', '/api/companies/'.$company->id()->toRfc4122().'/extension-tokens/'.$issued['id']);
        self::assertResponseStatusCodeSame(204);

        $this->extensionMe($client, $issued['token']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testRevokingUnknownTokenIsNotFound(): void
    {
        $client = static::createClient();
        [$companies, $users, $companyMembers] = $this->repositories();

        $company = $this->companyWithMember($companies, $users, $companyMembers, 'owner@example.com');
        $this->login($client, 'owner@example.com');

        $client->request('DELETE', '/api/companies/'.$company->id()->toRfc4122().'/extension-tokens/'.Uuid::v7()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $client = static::createClient();
        [$companies, $users, $companyMembers, $tokens] = $this->repositories();

        $company = $this->companyWithMember($companies, $users, $companyMembers, 'owner@example.com');
        $user = $users->findByEmail('owner@example.com');
        self::assertInstanceOf(User::class, $user);

        $secret = ExtensionTokenSecret::generate();
        ExtensionTokenBuilder::anExtensionToken()
            ->withCompany($company)
            ->withUser($user)
            ->withSecret($secret)
            // Срок жизни — P30D, выпуск 31 день назад заведомо за ним.
            ->withIssuedAt(new \DateTimeImmutable('-31 days'))
            ->persistWith($companies, $users, $tokens);

        $this->extensionMe($client, $secret->plaintext());

        self::assertResponseStatusCodeSame(401);
    }

    public function testTokenStopsWorkingWhenMembershipIsGone(): void
    {
        $client = static::createClient();
        [$companies, $users, , $tokens] = $this->repositories();

        // Токен выпущен и действителен, членства нет — так выглядит
        // исключённый из компании участник. Обработчик обязан
        // перепроверять членство, а не доверять факту выпуска.
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $user = $this->persistUser($users, 'excluded@example.com');
        $secret = ExtensionTokenSecret::generate();
        ExtensionTokenBuilder::anExtensionToken()
            ->withCompany($company)
            ->withUser($user)
            ->withSecret($secret)
            ->persistWith($companies, $users, $tokens);

        $this->extensionMe($client, $secret->plaintext());

        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownAndMissingTokensBothReturnJsonUnauthorized(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/extension/me');
        self::assertResponseStatusCodeSame(401);
        // JSON, не HTML-страница отладки: ApiAuthenticationEntryPoint
        // переиспользован и на этом firewall.
        self::assertJson((string) $client->getResponse()->getContent());

        $this->extensionMe($client, 'conwix_ext_not-a-real-token');
        self::assertResponseStatusCodeSame(401);
        self::assertJson((string) $client->getResponse()->getContent());
    }

    public function testSessionDoesNotGrantAccessToExtensionRoutes(): void
    {
        $client = static::createClient();
        [$companies, $users, $companyMembers] = $this->repositories();

        $this->companyWithMember($companies, $users, $companyMembers, 'owner@example.com');
        $this->login($client, 'owner@example.com');
        self::assertResponseIsSuccessful();

        // Контуры разведены: кука приложения не работает на маршрутах
        // расширения (ADR-010), даже если сессия живая.
        $client->request('GET', '/api/extension/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testExtensionTokenDoesNotGrantAccessToApplicationRoutes(): void
    {
        $client = static::createClient();
        [$companies, $users, $companyMembers] = $this->repositories();

        $company = $this->companyWithMember($companies, $users, $companyMembers, 'owner@example.com');
        $this->login($client, 'owner@example.com');
        $issued = $this->issueToken($client, $company);
        self::assertIsString($issued['token']);

        // Обратное направление того же разделения: bearer не подменяет
        // сессию на маршрутах приложения. Куку убираем — иначе успех
        // объяснялся бы ею, а не заголовком.
        $client->getCookieJar()->clear();
        $client->request('GET', '/api/auth/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$issued['token']]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testLastSeenIsRecordedOnPing(): void
    {
        $client = static::createClient();
        [$companies, $users, $companyMembers] = $this->repositories();

        $company = $this->companyWithMember($companies, $users, $companyMembers, 'owner@example.com');
        $this->login($client, 'owner@example.com');
        $issued = $this->issueToken($client, $company);
        self::assertIsString($issued['token']);
        self::assertIsString($issued['id']);

        // Читаем через DBAL, а не репозиторием: сущность, записанная внутри
        // запроса клиента, лежит в другой карте идентичности, и ORM отдала бы
        // её же, не сходив в базу. Проверяем то, что реально сохранено.
        self::assertNull($this->lastSeenAt($issued['id']), 'до первого обращения расширение не отмечалось');

        $this->extensionMe($client, $issued['token']);
        self::assertResponseIsSuccessful();

        // Контроль живости подключения (ADR-010): видно, у кого расширение
        // стоит и работает, без отдельной телеметрии.
        self::assertNotNull($this->lastSeenAt($issued['id']));
    }

    private function lastSeenAt(string $tokenId): ?string
    {
        $value = $this->entityManager()->getConnection()->fetchOne(
            'SELECT last_seen_at FROM extension_token WHERE id = :id',
            ['id' => $tokenId],
        );

        return \is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function issueToken(KernelBrowser $client, Company $company): array
    {
        $client->request('POST', '/api/companies/'.$company->id()->toRfc4122().'/extension-tokens');
        self::assertResponseStatusCodeSame(201);

        return $this->decodeResponse($client);
    }

    /**
     * @return array<string, mixed>
     */
    private function extensionMe(KernelBrowser $client, string $token): array
    {
        $client->request('GET', '/api/extension/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        return $this->decodeResponse($client);
    }

    private function companyWithMember(
        CompanyRepository $companies,
        DoctrineUserRepository $users,
        DoctrineCompanyMemberRepository $companyMembers,
        string $email,
    ): Company {
        $company = CompanyBuilder::aCompany()->withName('Acme LLC')->persistWith($companies);
        $user = $this->persistUser($users, $email);
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith($companies, $users, $companyMembers);

        return $company;
    }

    private function persistUser(DoctrineUserRepository $users, string $email): User
    {
        return UserBuilder::aUser()->withEmail($email)->withPasswordHash($this->hash())->persistWith($users);
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => self::PASSWORD], \JSON_THROW_ON_ERROR),
        );
    }

    private function hash(): string
    {
        /** @var PasswordHasherFactoryInterface $factory */
        $factory = static::getContainer()->get(PasswordHasherFactoryInterface::class);

        return $factory->getPasswordHasher(UserBuilder::aUser()->build())->hash(self::PASSWORD);
    }

    /**
     * @return array{0: CompanyRepository, 1: DoctrineUserRepository, 2: DoctrineCompanyMemberRepository, 3: DoctrineExtensionTokenRepository}
     */
    private function repositories(): array
    {
        $entityManager = $this->entityManager();

        /** @var CompanyRepository $companies */
        $companies = static::getContainer()->get(CompanyRepository::class);

        return [
            $companies,
            new DoctrineUserRepository($entityManager),
            new DoctrineCompanyMemberRepository($entityManager),
            new DoctrineExtensionTokenRepository($entityManager),
        ];
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

        if ('' === $content) {
            return [];
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $payload;
    }
}
