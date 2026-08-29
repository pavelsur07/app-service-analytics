<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\ChangeCompanyStatusAction;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\CompanyStatus;
use App\Identity\Domain\ValueObject\ExtensionTokenSecret;
use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineExtensionTokenRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Tests\Support\Builder\AdministratorBuilder;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\ExtensionTokenBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Блокировка аккаунта останавливает доступ (ADR-017) — обязательное
 * покрытие: изоляция данных клиента.
 *
 * Через HTTP, потому что предмет проверки живёт в подписчике
 * на kernel.controller, а не в контроллере, и вызвать его иначе нечем
 * (CLAUDE.md §9). Проверяется не код ответа сам по себе, а то, что
 * данные заблокированной компании не уезжают наружу.
 */
final class BlockedCompanyAccessTest extends WebTestCase
{
    public function testBlockedCompanyMemberIsDeniedOnTheNextRequestWithNoDataLeak(): void
    {
        $client = static::createClient();
        [$companies, $users, $companyMembers] = $this->repositories();

        $user = $this->userWithCompany($companies, $users, $companyMembers);
        $companyId = $this->companyIdOf($companyMembers, $user);
        $client->loginUser($user, 'api');

        $this->salesFacts()->upsertAll([
            SalesFactBuilder::aSalesFact()->withCompanyId($this->uuidOf($companyId))->withSourceRowId('secret|SKU-1')->build(),
        ]);

        // Сессия жива и доступ есть — иначе тест проходил бы и на
        // сломанной аутентификации.
        $client->request('GET', $this->salesFactsUrl($companyId));
        self::assertResponseIsSuccessful();

        $this->block($companies, $companyId);

        // Следующий же запрос той же живой сессией. Сессии в Redis
        // никто не сбрасывал и не должен (ADR-017).
        $client->request('GET', $this->salesFactsUrl($companyId));

        self::assertResponseStatusCodeSame(403);
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString('secret', $content, 'тело отказа не должно содержать данные компании');
        self::assertSame('company_blocked', $this->decode($client)['code']);
    }

    public function testAccessReturnsAfterActivation(): void
    {
        $client = static::createClient();
        [$companies, $users, $companyMembers] = $this->repositories();

        $user = $this->userWithCompany($companies, $users, $companyMembers, 'back@example.com');
        $companyId = $this->companyIdOf($companyMembers, $user);
        $client->loginUser($user, 'api');

        $this->block($companies, $companyId);
        $client->request('GET', $this->salesFactsUrl($companyId));
        self::assertResponseStatusCodeSame(403);

        // Данные не удалялись и не помечались неполными — включение
        // возвращает доступ к тому же, что было.
        $this->activate($companies, $companyId);
        $client->request('GET', $this->salesFactsUrl($companyId));
        self::assertResponseIsSuccessful();
    }

    public function testExtensionContourOfABlockedCompanyIsClosedToo(): void
    {
        $client = static::createClient();
        [$companies, $users, $companyMembers, $tokens] = $this->repositories();

        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $user = $this->persistUser($users, 'ext@example.com');
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith($companies, $users, $companyMembers);

        $secret = ExtensionTokenSecret::generate();
        ExtensionTokenBuilder::anExtensionToken()
            ->withCompany($company)
            ->withUser($user)
            ->withSecret($secret)
            ->persistWith($companies, $users, $tokens);

        $companyId = $company->id()->toRfc4122();
        $url = \sprintf('/api/extension/companies/%s/tracked-skus', $companyId);

        $client->request('GET', $url, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret->plaintext()]);
        self::assertResponseIsSuccessful();

        $this->block($companies, $companyId);

        // Тот же подписчик закрывает и этот контур: он срабатывает
        // по атрибуту маршрута companyId, независимо от firewall.
        // Отдельной проверки в контуре расширения не потребовалось.
        $client->request('GET', $url, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret->plaintext()]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('company_blocked', $this->decode($client)['code']);
    }

    public function testStrangerStillSeesTheGenericDenialNotTheBlockedOne(): void
    {
        $client = static::createClient();
        [$companies, $users, $companyMembers] = $this->repositories();

        $stranger = $this->userWithCompany($companies, $users, $companyMembers, 'stranger@example.com');
        $blocked = CompanyBuilder::aCompany()->persistWith($companies);
        $blockedId = $blocked->id()->toRfc4122();
        $this->block($companies, $blockedId);

        $client->loginUser($stranger, 'api');
        $client->request('GET', $this->salesFactsUrl($blockedId));

        self::assertResponseStatusCodeSame(403);
        // Постороннему статус чужой компании знать незачем: различие
        // кодов доступно только подтверждённому участнику.
        self::assertSame('company_access_denied', $this->decode($client)['code']);
    }

    private function block(CompanyRepository $companies, string $companyId): void
    {
        $actor = AdministratorBuilder::aBootstrapSuperAdmin()
            ->withEmail('boss+'.$companyId.'@conwix.local')
            ->persistWith(new DoctrineAdministratorRepository($this->entityManager()));

        self::assertTrue((new ChangeCompanyStatusAction($companies))($companyId, CompanyStatus::Blocked, $actor));
    }

    private function activate(CompanyRepository $companies, string $companyId): void
    {
        $actor = new DoctrineAdministratorRepository($this->entityManager());
        $administrator = $actor->findByEmail('boss+'.$companyId.'@conwix.local');
        self::assertNotNull($administrator);

        self::assertTrue((new ChangeCompanyStatusAction($companies))($companyId, CompanyStatus::Active, $administrator));
    }

    private function userWithCompany(
        CompanyRepository $companies,
        DoctrineUserRepository $users,
        DoctrineCompanyMemberRepository $companyMembers,
        string $email = 'owner@example.com',
    ): User {
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $user = $this->persistUser($users, $email);
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith($companies, $users, $companyMembers);

        return $user;
    }

    private function persistUser(DoctrineUserRepository $users, string $email): User
    {
        return \App\Tests\Support\Builder\UserBuilder::aUser()->withEmail($email)->persistWith($users);
    }

    private function companyIdOf(DoctrineCompanyMemberRepository $companyMembers, User $user): string
    {
        $companyId = $this->entityManager()->getConnection()->fetchOne(
            'SELECT company_id FROM company_member WHERE user_id = :userId LIMIT 1',
            ['userId' => $user->id()->toRfc4122()],
        );
        self::assertIsString($companyId);

        return $companyId;
    }

    private function uuidOf(string $companyId): \Symfony\Component\Uid\Uuid
    {
        return \Symfony\Component\Uid\Uuid::fromString($companyId);
    }

    private function salesFactsUrl(string $companyId): string
    {
        return \sprintf('/api/companies/%s/ingestion/ozon/sales-facts', $companyId);
    }

    private function salesFacts(): SalesFactRepository
    {
        /** @var SalesFactRepository $salesFacts */
        $salesFacts = static::getContainer()->get(SalesFactRepository::class);

        return $salesFacts;
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
    private function decode(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $payload;
    }
}
