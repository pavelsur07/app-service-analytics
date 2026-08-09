<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\CompanyMember;
use App\Identity\Domain\ValueObject\CompanyMemberRole;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Tests\Support\Builder\SalesFactBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Ядро приёмки ТЗ (tracer bullet #2, план в
 * /home/deploy/.claude/plans/rippling-churning-scroll.md): единая точка
 * проверки доступа к company-scoped маршрутам, проверяется через
 * существующий company-scoped маршрут (sales-facts) — CompanyAccessSubscriber
 * сам по себе универсален по имени параметра, отдельного маршрута для
 * теста не заводим.
 */
final class CompanyAccessSubscriberTest extends WebTestCase
{
    public function testUnauthenticatedRequestToCompanyScopedRouteReturns401(): void
    {
        $client = static::createClient();

        $client->request('GET', \sprintf('/api/companies/%s/ingestion/ozon/sales-facts', Uuid::v7()->toRfc4122()));

        self::assertResponseStatusCodeSame(401);
    }

    public function testMemberOfOneCompanyIsDeniedAnotherCompanyWithNoDataLeak(): void
    {
        $client = static::createClient();
        $salesFacts = $this->salesFacts();
        $entityManager = $this->entityManager();
        $users = new DoctrineUserRepository($entityManager);
        $companyMembers = new DoctrineCompanyMemberRepository($entityManager);

        $ownCompany = Uuid::v7();
        $otherCompany = Uuid::v7();

        $user = UserBuilder::aUser()->withEmail('member-a@example.com')->persistWith($users);
        $companyMembers->add(CompanyMember::create($ownCompany, $user->id(), CompanyMemberRole::Owner));
        $client->loginUser($user, 'api');

        $salesFacts->upsertAll([
            SalesFactBuilder::aSalesFact()->withCompanyId($otherCompany)->withSourceRowId('secret|SKU-1')->build(),
        ]);

        $client->request('GET', \sprintf('/api/companies/%s/ingestion/ozon/sales-facts', $otherCompany->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString('secret', $content, 'тело 403 не должно содержать данные чужой компании');

        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('company_access_denied', $payload['code']);
    }

    /**
     * ТЗ, критерий приёмки 2: участник двух компаний получает данные
     * строго по companyId из URL, не по сессии — независимо от того,
     * в каком порядке компании запрашиваются.
     */
    public function testMemberOfTwoCompaniesSeesOnlyTheCompanyRequestedInTheUrl(): void
    {
        $client = static::createClient();
        $salesFacts = $this->salesFacts();
        $entityManager = $this->entityManager();
        $users = new DoctrineUserRepository($entityManager);
        $companyMembers = new DoctrineCompanyMemberRepository($entityManager);

        $companyA = Uuid::v7();
        $companyB = Uuid::v7();

        $user = UserBuilder::aUser()->withEmail('multi-member@example.com')->persistWith($users);
        $companyMembers->add(CompanyMember::create($companyA, $user->id(), CompanyMemberRole::Owner));
        $companyMembers->add(CompanyMember::create($companyB, $user->id(), CompanyMemberRole::Owner));
        $client->loginUser($user, 'api');

        $salesFacts->upsertAll([
            SalesFactBuilder::aSalesFact()->withCompanyId($companyA)->withSourceRowId('A-1|SKU-1')->build(),
            SalesFactBuilder::aSalesFact()->withCompanyId($companyB)->withSourceRowId('B-1|SKU-1')->build(),
        ]);

        $client->request('GET', \sprintf('/api/companies/%s/ingestion/ozon/sales-facts', $companyA->toRfc4122()));
        self::assertResponseIsSuccessful();
        $itemsA = $this->sourceRowIdsOf($client);
        self::assertSame(['A-1|SKU-1'], $itemsA);

        $client->request('GET', \sprintf('/api/companies/%s/ingestion/ozon/sales-facts', $companyB->toRfc4122()));
        self::assertResponseIsSuccessful();
        $itemsB = $this->sourceRowIdsOf($client);
        self::assertSame(['B-1|SKU-1'], $itemsB);
    }

    private function salesFacts(): SalesFactRepository
    {
        /** @var SalesFactRepository $salesFacts */
        $salesFacts = static::getContainer()->get(SalesFactRepository::class);

        return $salesFacts;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }

    /**
     * @return list<string>
     */
    private function sourceRowIdsOf(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload['items']);

        $sourceRowIds = [];
        foreach ($payload['items'] as $item) {
            self::assertIsArray($item);
            self::assertIsString($item['sourceRowId']);
            $sourceRowIds[] = $item['sourceRowId'];
        }

        return $sourceRowIds;
    }
}
