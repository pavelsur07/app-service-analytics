<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\ValueObject\AdminRole;
use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\AdministratorBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Разделение контуров (ADR-007) — обязательное покрытие: проверяется
 * не «контроллер вернул 200», а то, что сессия одного контура
 * не открывает другой. Иначе об этом узнал бы первый же клиент,
 * и узнал бы дорого.
 *
 * Через HTTP, потому что предмет проверки живёт в firewall и
 * access_control — вызвать их другим способом нечем (CLAUDE.md §9).
 */
final class AdminContourTest extends WebTestCase
{
    public function testAdminLogsInAndSeesOwnRole(): void
    {
        $client = static::createClient();
        $administrators = $this->administrators();

        AdministratorBuilder::anAdministrator()
            ->withEmail('ops@conwix.local')
            ->withRole(AdminRole::Admin)
            ->withPasswordHash($this->hash('admin-password'))
            ->persistWith($administrators);

        $this->loginAdmin($client, 'ops@conwix.local', 'admin-password');

        self::assertResponseIsSuccessful();
        $payload = $this->decodeResponse($client);
        self::assertSame('ops@conwix.local', $payload['email']);
        self::assertSame('admin', $payload['role']);
    }

    public function testSuperAdminRoleIsReportedAsSuperAdmin(): void
    {
        $client = static::createClient();
        $administrators = $this->administrators();

        AdministratorBuilder::anAdministrator()
            ->withEmail('boss@conwix.local')
            ->withRole(AdminRole::SuperAdmin)
            ->withPasswordHash($this->hash('boss-password'))
            ->persistWith($administrators);

        $this->loginAdmin($client, 'boss@conwix.local', 'boss-password');

        self::assertResponseIsSuccessful();
        self::assertSame('super_admin', $this->decodeResponse($client)['role']);
    }

    public function testSellerCannotLogIntoAdminContourWithOwnCredentials(): void
    {
        $client = static::createClient();
        [, $users] = $this->repositories();

        // Тот же email и пароль, что у продавца, — и контур
        // администраторов о нём не знает: разные таблицы (ADR-007),
        // разные провайдеры.
        UserBuilder::aUser()
            ->withEmail('seller@example.com')
            ->withPasswordHash($this->hash('seller-password'))
            ->persistWith($users);

        $this->loginAdmin($client, 'seller@example.com', 'seller-password');

        self::assertResponseStatusCodeSame(401);
    }

    public function testSellerSessionDoesNotOpenAdminContour(): void
    {
        $client = static::createClient();
        [, $users] = $this->repositories();

        $user = UserBuilder::aUser()->withEmail('member@example.com')->persistWith($users);
        $client->loginUser($user, 'api');

        // Сессия продавца жива — это доказывает соседний запрос,
        // иначе тест проходил бы и на выключенной аутентификации.
        $client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/admin/auth/me');
        self::assertResponseStatusCodeSame(401, 'сессия продавца не должна опознаваться контуром администраторов');
    }

    public function testAdminSessionDoesNotOpenCompanyScopedRoutes(): void
    {
        $client = static::createClient();
        $administrators = $this->administrators();
        [$companies, $users, $companyMembers] = $this->repositories();

        AdministratorBuilder::anAdministrator()
            ->withEmail('ops2@conwix.local')
            ->withPasswordHash($this->hash('admin-password'))
            ->persistWith($administrators);

        $user = UserBuilder::aUser()->withEmail('owner2@example.com')->persistWith($users);
        $member = CompanyMemberBuilder::aCompanyMember()->withUser($user)->persistWith($companies, $users, $companyMembers);

        $this->loginAdmin($client, 'ops2@conwix.local', 'admin-password');
        self::assertResponseIsSuccessful();

        // Роль администратора не заменяет членства в компании (ADR-002):
        // системный контур не даёт доступа к данным арендатора сам
        // по себе, и вход в них — отдельное событие с отдельным
        // аудиторским следом, которого здесь ещё нет.
        $client->request('GET', \sprintf('/api/companies/%s/ingestion/ozon/sales-facts', $member->companyId()->toRfc4122()));
        self::assertResponseStatusCodeSame(401);
    }

    public function testPlantedAdministratorTokenGrantsNoAccessToCompanyData(): void
    {
        $client = static::createClient();
        $administrators = $this->administrators();
        [$companies, $users, $companyMembers] = $this->repositories();

        $administrator = AdministratorBuilder::anAdministrator()
            ->withEmail('ops3@conwix.local')
            ->persistWith($administrators);
        $user = UserBuilder::aUser()->withEmail('owner3@example.com')->persistWith($users);
        $member = CompanyMemberBuilder::aCompanyMember()->withUser($user)->persistWith($companies, $users, $companyMembers);

        // Патологическое состояние: администратор посажен в firewall
        // продавца напрямую, минуя вход. Так контуры не пересекаются
        // никогда — но проверяется именно то, что и в этом случае
        // доступа к данным компании не возникает: UserProvider
        // отказывается обновлять чужой тип (supportsClass), и токен
        // отбрасывается раньше контроллера.
        $client->loginUser($administrator, 'api');

        $client->request('GET', \sprintf('/api/companies/%s/ingestion/ozon/sales-facts', $member->companyId()->toRfc4122()));

        // 403, а не 401: токен есть, но access_control требует
        // ROLE_USER, а администратор её не имеет — Administrator::getRoles()
        // отдаёт только роль своего контура. Отказ наступает раньше
        // CompanyAccessSubscriber, поэтому его guard здесь и не нужен —
        // он защищает от маршрута системного контура с параметром
        // companyId, которого сегодня нет ни одного.
        self::assertResponseStatusCodeSame(403);
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString('sourceRowId', $content, 'тело отказа не должно содержать данные компании');
    }

    public function testAdminPingStaysPublic(): void
    {
        $client = static::createClient();

        // На нём висит smoke-проверка выкладки и стартовый экран
        // админки: закрытие ^/api/admin/ не должно было его задеть.
        $client->request('GET', '/api/admin/ping');

        self::assertResponseIsSuccessful();
    }

    public function testUnauthenticatedAdminRouteReturnsJsonNotHtml(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/admin/auth/me');

        self::assertResponseStatusCodeSame(401);
        self::assertSame('unauthenticated', $this->decodeResponse($client)['code']);
    }

    private function loginAdmin(KernelBrowser $client, string $email, string $password): void
    {
        $client->request(
            'POST',
            '/api/admin/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => $password], \JSON_THROW_ON_ERROR),
        );
    }

    private function hash(string $plainPassword): string
    {
        /** @var PasswordHasherFactoryInterface $factory */
        $factory = static::getContainer()->get(PasswordHasherFactoryInterface::class);

        return $factory->getPasswordHasher(AdministratorBuilder::anAdministrator()->build())->hash($plainPassword);
    }

    private function administrators(): DoctrineAdministratorRepository
    {
        return new DoctrineAdministratorRepository($this->entityManager());
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
