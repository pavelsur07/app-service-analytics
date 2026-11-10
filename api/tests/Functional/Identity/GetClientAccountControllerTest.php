<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\ChangeCompanyStatusAction;
use App\Identity\Domain\AuditAction;
use App\Identity\Domain\ValueObject\CompanyStatus;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Identity\Infrastructure\Query\CompanyForAdminQuery;
use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Identity\Infrastructure\Repository\DoctrineAuditRecordRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineMarketplaceAccountRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\AdministratorBuilder;
use App\Tests\Support\Builder\AuditRecordBuilder;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\UserBuilder;
use App\Tests\Support\SessionWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

/**
 * @phpstan-type CardPayload array{
 *   company: array{id: string, status: string, hasConfirmedUser: bool},
 *   members: list<array{id: string, email: string, emailConfirmedAt: ?string}>,
 *   connections: list<array{id: string, externalShopId: string, state: string, advertisingState: ?string}>,
 *   recentAudit: list<array{id: string, actorEmail: ?string, actorType: string, occurredAt: string}>,
 *   membersTruncated: bool, connectionsTruncated: bool, auditTruncated: bool
 * }
 */
final class GetClientAccountControllerTest extends SessionWebTestCase
{
    /** @return iterable<string, array{bool}> */
    public static function roles(): iterable
    {
        yield 'Admin' => [false];
        yield 'SuperAdmin' => [true];
    }

    #[DataProvider('roles')]
    public function testBothRolesReadOnlySelectedCompanyAndAccessIsAudited(bool $superAdmin): void
    {
        $client = self::createClient();
        $em = $this->em();
        $companies = new DoctrineCompanyRepository($em);
        $users = new DoctrineUserRepository($em);
        $members = new DoctrineCompanyMemberRepository($em);
        $accounts = new DoctrineMarketplaceAccountRepository($em);
        $audit = new DoctrineAuditRecordRepository($em);
        $company = CompanyBuilder::aCompany()->withName('Selected')->persistWith($companies);
        $other = CompanyBuilder::aCompany()->withName('Other tenant')->persistWith($companies);
        $owner = UserBuilder::aUser()->withEmail('selected@example.test')->unconfirmed()->persistWith($users);
        $otherOwner = UserBuilder::aUser()->withEmail('other@example.test')->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($owner)->persistWith($companies, $users, $members);
        CompanyMemberBuilder::aCompanyMember()->withCompany($other)->withUser($otherOwner)->persistWith($companies, $users, $members);
        MarketplaceAccountBuilder::aMarketplaceAccount()->withCompany($company)->withExternalShopId('selected-shop')
            ->withCredentials('must-not-leak-ciphertext', 1)->withState(MarketplaceAccountState::Broken)->withAdvertisingConnected()->persistWith($companies, $accounts);
        MarketplaceAccountBuilder::aMarketplaceAccount()->withCompany($other)->withExternalShopId('other-shop')->persistWith($companies, $accounts);
        AuditRecordBuilder::anAuditRecord()->withCompanyId($company->id())->withActorUserId($owner->id())
            ->withAction(AuditAction::MarketplaceCredentialsReplaced)->withChange('private-before', 'private-after')->persistWith($audit);
        AuditRecordBuilder::anAuditRecord()->withCompanyId($other->id())->withActorUserId($otherOwner->id())
            ->withAction(AuditAction::CompanyBlocked)->persistWith($audit);
        $em->flush();
        $administrators = new DoctrineAdministratorRepository($em);
        $admin = ($superAdmin ? AdministratorBuilder::aBootstrapSuperAdmin() : AdministratorBuilder::anAdministrator())->persistWith($administrators);
        $this->authenticateAdministrator($client, $admin);

        $client->request('GET', '/api/admin/companies/'.$company->id());

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse()->getContent());
        self::assertSame($company->id()->toRfc4122(), $payload['company']['id']);
        self::assertSame('active', $payload['company']['status']);
        self::assertFalse($payload['company']['hasConfirmedUser']);
        self::assertCount(1, $payload['members']);
        self::assertSame('selected@example.test', $payload['members'][0]['email']);
        self::assertNull($payload['members'][0]['emailConfirmedAt']);
        self::assertCount(1, $payload['connections']);
        self::assertSame('selected-shop', $payload['connections'][0]['externalShopId']);
        self::assertSame('broken', $payload['connections'][0]['state']);
        self::assertSame('active', $payload['connections'][0]['advertisingState']);
        self::assertCount(1, $payload['recentAudit']);
        self::assertSame('selected@example.test', $payload['recentAudit'][0]['actorEmail']);
        self::assertFalse($payload['membersTruncated']);
        self::assertFalse($payload['connectionsTruncated']);
        self::assertFalse($payload['auditTruncated']);
        $body = (string) $client->getResponse()->getContent();
        foreach (['must-not-leak', 'private-before', 'private-after', 'password', 'other@example.test', 'other-shop'] as $secret) {
            self::assertStringNotContainsString($secret, $body);
        }
        $record = $this->em()->getConnection()->fetchAssociative('SELECT actor_admin_id, previous_value, new_value FROM audit_record WHERE company_id = :id AND action = :action', ['id' => $company->id()->toRfc4122(), 'action' => AuditAction::CompanyViewed]);
        self::assertNotFalse($record);
        self::assertSame($admin->id()->toRfc4122(), $record['actor_admin_id']);
        self::assertNull($record['previous_value']);
        self::assertNull($record['new_value']);
        $changeStatus = self::getContainer()->get(ChangeCompanyStatusAction::class);
        self::assertInstanceOf(ChangeCompanyStatusAction::class, $changeStatus);
        $changeStatus($company->id()->toRfc4122(), CompanyStatus::Blocked, $admin);
        $client->request('GET', '/api/admin/companies/'.$company->id());
        self::assertResponseIsSuccessful();
        self::assertSame('blocked', $this->payload($client->getResponse()->getContent())['company']['status']);
    }

    public function testSellerSessionAndAnonymousCannotReadTheCard(): void
    {
        $client = self::createClient();
        $company = CompanyBuilder::aCompany()->persistWith(new DoctrineCompanyRepository($this->em()));
        $url = '/api/admin/companies/'.$company->id();
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(401);
        $user = UserBuilder::aUser()->persistWith(new DoctrineUserRepository($this->em()));
        $client->loginUser($user, 'seller');
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminWithoutSecondFactorCannotReadTheCard(): void
    {
        $client = self::createClient();
        $admin = AdministratorBuilder::aBootstrapSuperAdmin()->persistWith(new DoctrineAdministratorRepository($this->em()));
        $client->loginUser($admin, 'admin');
        $client->request('GET', '/api/admin/companies/'.Uuid::v7());
        self::assertResponseStatusCodeSame(401);
    }

    public function testMissingAndInvalidIdentifiersReturnNotFound(): void
    {
        $client = self::createClient();
        $admin = AdministratorBuilder::aBootstrapSuperAdmin()->persistWith(new DoctrineAdministratorRepository($this->em()));
        $this->authenticateAdministrator($client, $admin);
        $client->request('GET', '/api/admin/companies/'.Uuid::v7());
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('company_not_found', (string) $client->getResponse()->getContent());
        $client->request('GET', '/api/admin/companies/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT count(*)::int FROM audit_record WHERE action = :action', ['action' => AuditAction::CompanyViewed]));
    }

    public function testRecentAuditIsBoundedOrderedAndHasNoUnrelatedActions(): void
    {
        $client = self::createClient();
        $em = $this->em();
        $company = CompanyBuilder::aCompany()->persistWith(new DoctrineCompanyRepository($em));
        $admin = AdministratorBuilder::aBootstrapSuperAdmin()->persistWith(new DoctrineAdministratorRepository($em));
        $audit = new DoctrineAuditRecordRepository($em);
        $ids = [];
        for ($i = 0; $i <= CompanyForAdminQuery::AUDIT_LIMIT; ++$i) {
            $record = AuditRecordBuilder::anAuditRecord()->withCompanyId($company->id())->withActorAdminId($admin->id())
                ->withAction(AuditAction::CompanyBlocked)->withOccurredAt(new \DateTimeImmutable('2026-11-01 10:00:00'))->persistWith($audit);
            $ids[] = $record->id()->toRfc4122();
        }
        AuditRecordBuilder::anAuditRecord()->withCompanyId($company->id())->withActorAdminId($admin->id())
            ->withAction('unknown.private_action')->persistWith($audit);
        $em->flush();
        $this->authenticateAdministrator($client, $admin);
        $client->request('GET', '/api/admin/companies/'.$company->id());
        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse()->getContent());
        rsort($ids);
        self::assertSame(\array_slice($ids, 0, CompanyForAdminQuery::AUDIT_LIMIT), array_column($payload['recentAudit'], 'id'));
        self::assertTrue($payload['auditTruncated']);
        self::assertSame('admin', $payload['recentAudit'][0]['actorType']);
        self::assertSame($admin->email(), $payload['recentAudit'][0]['actorEmail']);
        self::assertStringEndsWith('+00:00', $payload['recentAudit'][0]['occurredAt']);
    }

    public function testEmptyCompanyAndBoundedChildLists(): void
    {
        $client = self::createClient();
        $em = $this->em();
        $companies = new DoctrineCompanyRepository($em);
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $admin = AdministratorBuilder::aBootstrapSuperAdmin()->persistWith(new DoctrineAdministratorRepository($em));
        $this->authenticateAdministrator($client, $admin);
        $client->request('GET', '/api/admin/companies/'.$company->id());
        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse()->getContent());
        self::assertSame([], $payload['members']);
        self::assertSame([], $payload['connections']);
        self::assertSame([], $payload['recentAudit']);
        $em = $this->em();
        $companies = new DoctrineCompanyRepository($em);
        $users = new DoctrineUserRepository($em);
        $members = new DoctrineCompanyMemberRepository($em);
        $accounts = new DoctrineMarketplaceAccountRepository($em);
        for ($i = 0; $i < 51; ++$i) {
            $user = UserBuilder::aUser()->withEmail('member'.$i.'@example.test')->persistWith($users);
            CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith($companies, $users, $members);
            MarketplaceAccountBuilder::aMarketplaceAccount()->withCompany($company)->withExternalShopId('shop-'.$i)->persistWith($companies, $accounts);
        }
        $client->request('GET', '/api/admin/companies/'.$company->id());
        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse()->getContent());
        self::assertCount(50, $payload['members']);
        self::assertTrue($payload['membersTruncated']);
        self::assertTrue($payload['company']['hasConfirmedUser']);
        self::assertCount(50, $payload['connections']);
        self::assertTrue($payload['connectionsTruncated']);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @return CardPayload */
    private function payload(string|false $body): array
    {
        self::assertIsString($body);
        /** @var CardPayload $payload */
        $payload = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);

        return $payload;
    }
}
