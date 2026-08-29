<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\AuditAction;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\ValueObject\CompanyStatus;
use App\Identity\Infrastructure\Query\AllCompaniesForAdminQuery;
use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\AdministratorBuilder;
use App\Tests\Support\Builder\CompanyBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Экран управления аккаунтами (ADR-017): межарендаторный список,
 * регистрация аккаунта одной транзакцией, блокировка и включение.
 *
 * Через HTTP, потому что предмет — права двух ролей и атомарность
 * регистрации, и оба живут на границе (CLAUDE.md §9).
 */
final class ClientAccountsControllerTest extends WebTestCase
{
    public function testAdminSeesAccountsOfAllTenants(): void
    {
        $client = static::createClient();
        $companies = $this->companies();

        CompanyBuilder::aCompany()->withName('Первая')->persistWith($companies);
        CompanyBuilder::aCompany()->withName('Вторая')->persistWith($companies);

        $this->loginAdmin($client);
        $client->request('GET', '/api/admin/companies');

        self::assertResponseIsSuccessful();
        $payload = $this->decode($client);

        // Смысл экрана: аккаунты разных арендаторов в одном списке.
        // Это и есть межарендаторное чтение по исключению §1.
        self::assertIsArray($payload['items']);
        self::assertGreaterThanOrEqual(2, $payload['total']);
        $names = array_column($payload['items'], 'name');
        self::assertContains('Первая', $names);
        self::assertContains('Вторая', $names);
        self::assertSame(1, $payload['page']);
        self::assertSame(AllCompaniesForAdminQuery::DEFAULT_LIMIT, $payload['per_page']);
    }

    public function testSellerCannotReachTheAccountsList(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/admin/companies');

        self::assertResponseStatusCodeSame(401);
    }

    public function testLimitAboveMaximumIsRejectedNotSilentlyTruncated(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $client->request('GET', '/api/admin/companies?limit='.(AllCompaniesForAdminQuery::MAX_LIMIT + 1));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('limit_too_large', $this->decode($client)['code']);
    }

    public function testPageBeyondTheLastIsRejected(): void
    {
        $client = static::createClient();
        CompanyBuilder::aCompany()->persistWith($this->companies());
        $this->loginAdmin($client);

        $client->request('GET', '/api/admin/companies?page=999');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('page_out_of_range', $this->decode($client)['code']);
    }

    public function testRegisterCreatesCompanyOwnerMembershipAndJournalEntry(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $client->request(
            'POST',
            '/api/admin/companies',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'name' => 'Ромашка ООО',
                'ownerEmail' => 'Owner@Romashka.Test',
                'ownerPassword' => 'long-enough-password',
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);
        $payload = $this->decode($client);
        self::assertSame('Ромашка ООО', $payload['name']);
        self::assertSame('active', $payload['status'], 'новый аккаунт работает сразу');
        $companyId = $payload['id'];
        self::assertIsString($companyId);

        $owner = (new DoctrineUserRepository($this->entityManager()))->findByEmail('owner@romashka.test');
        self::assertNotNull($owner, 'владелец заводится тем же действием');

        $membership = $this->connection()->fetchAssociative(
            'SELECT role FROM company_member WHERE company_id = :c AND user_id = :u',
            ['c' => $companyId, 'u' => $owner->id()->toRfc4122()],
        );
        self::assertIsArray($membership);
        self::assertSame('owner', $membership['role']);

        $record = $this->connection()->fetchAssociative(
            'SELECT actor_admin_id, new_value FROM audit_record WHERE company_id = :c AND action = :a',
            ['c' => $companyId, 'a' => AuditAction::CompanyRegistered],
        );
        self::assertIsArray($record);
        self::assertSame('owner@romashka.test', $record['new_value']);
    }

    public function testTakenOwnerEmailLeavesNoOrphanCompany(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $body = static fn (string $name): string => json_encode([
            'name' => $name,
            'ownerEmail' => 'dup@romashka.test',
            'ownerPassword' => 'long-enough-password',
        ], \JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/admin/companies', server: ['CONTENT_TYPE' => 'application/json'], content: $body('Первая'));
        self::assertResponseStatusCodeSame(201);

        $client->request('POST', '/api/admin/companies', server: ['CONTENT_TYPE' => 'application/json'], content: $body('Вторая'));
        self::assertResponseStatusCodeSame(409);

        // Главное здесь: транзакция откатилась целиком. Компания
        // без единого участника — аккаунт, в который некому войти
        // и который нечем удалить.
        $orphan = $this->connection()->fetchOne(
            'SELECT count(*) FROM company WHERE name = :name',
            ['name' => 'Вторая'],
        );
        self::assertIsNumeric($orphan);
        self::assertSame(0, (int) $orphan);
    }

    public function testShortOwnerPasswordIsRejected(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $client->request(
            'POST',
            '/api/admin/companies',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'Ромашка', 'ownerEmail' => 'ok@test.local', 'ownerPassword' => 'short'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSame('owner_password_too_short', $this->decode($client)['code']);
    }

    public function testBlockAndActivateFromTheScreen(): void
    {
        $client = static::createClient();
        $company = CompanyBuilder::aCompany()->persistWith($this->companies());
        $companyId = $company->id()->toRfc4122();
        $this->loginAdmin($client);

        $this->setStatus($client, $companyId, CompanyStatus::Blocked);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->decode($client)['changed']);
        self::assertSame('blocked', $this->storedStatus($companyId));

        // Повтор — не ошибка и не второй след.
        $this->setStatus($client, $companyId, CompanyStatus::Blocked);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->decode($client)['changed']);

        $this->setStatus($client, $companyId, CompanyStatus::Active);
        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->storedStatus($companyId));
    }

    public function testLowerRoleAlsoManagesAccounts(): void
    {
        $client = static::createClient();
        $company = CompanyBuilder::aCompany()->persistWith($this->companies());
        $administrators = new DoctrineAdministratorRepository($this->entityManager());

        $boss = AdministratorBuilder::aBootstrapSuperAdmin()->withEmail('boss@conwix.local')->persistWith($administrators);
        $admin = AdministratorBuilder::anAdministrator()->withEmail('ops@conwix.local')->createdBy($boss)->persistWith($administrators);
        $client->loginUser($admin, 'admin');

        // Управление аккаунтами доступно обеим ролям (ADR-017),
        // в отличие от заведения администраторов.
        $this->setStatus($client, $company->id()->toRfc4122(), CompanyStatus::Blocked);

        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/admin/companies');
        self::assertResponseIsSuccessful();
    }

    public function testUnknownStatusIsRejected(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $client->request(
            'POST',
            \sprintf('/api/admin/companies/%s/status', Uuid::v7()->toRfc4122()),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['status' => 'deleted'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSame('status_invalid', $this->decode($client)['code']);
    }

    private function setStatus(KernelBrowser $client, string $companyId, CompanyStatus $status): void
    {
        $client->request(
            'POST',
            \sprintf('/api/admin/companies/%s/status', $companyId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['status' => $status->value], \JSON_THROW_ON_ERROR),
        );
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $administrator = AdministratorBuilder::aBootstrapSuperAdmin()
            ->withEmail('admin@conwix.local')
            ->persistWith(new DoctrineAdministratorRepository($this->entityManager()));

        $client->loginUser($administrator, 'admin');
    }

    private function storedStatus(string $companyId): string
    {
        $status = $this->connection()->fetchOne('SELECT status FROM company WHERE id = :id', ['id' => $companyId]);
        self::assertIsString($status);

        return $status;
    }

    private function companies(): CompanyRepository
    {
        /** @var CompanyRepository $companies */
        $companies = static::getContainer()->get(CompanyRepository::class);

        return $companies;
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return $this->entityManager()->getConnection();
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
    private function decode(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $payload;
    }
}
