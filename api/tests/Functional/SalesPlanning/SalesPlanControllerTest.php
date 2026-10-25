<?php

declare(strict_types=1);

namespace App\Tests\Functional\SalesPlanning;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\SalesPlanBuilder;
use App\Tests\Support\Builder\UserBuilder;
use App\Tests\Support\SessionWebTestCase as WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

/**
 * Через HTTP — только то, что иначе не проверить (CLAUDE.md §9):
 * изоляция арендаторов и разбор тела на границе доверия. Сценарии —
 * уровнем ниже, в SalesPlanScenariosTest.
 */
final class SalesPlanControllerTest extends WebTestCase
{
    public function testForeignCompanyPathIsForbidden(): void
    {
        $client = static::createClient();
        $this->loginAsCompanyMember($client);
        $foreign = CompanyBuilder::aCompany()->persistWith($this->companies());
        $foreignId = $foreign->id()->toRfc4122();

        $client->request('GET', "/api/companies/{$foreignId}/sales-plans");
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $client->request('POST', "/api/companies/{$foreignId}/sales-plans", server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testForeignPlanUnderOwnCompanyPathIsNotFound(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $foreignPlan = SalesPlanBuilder::aSalesPlan()
            ->withCompanyId(CompanyBuilder::aCompany()->persistWith($this->companies())->id())
            ->persistWith($this->plans());
        $base = "/api/companies/{$company->id()->toRfc4122()}/sales-plans/{$foreignPlan->id()->toRfc4122()}";

        // План — коммерческая тайна: зная идентификатор, чужой план нельзя
        // ни прочитать, ни дополнить через путь своей компании.
        $client->request('GET', $base);
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('PUT', "{$base}/skus", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'version' => $foreignPlan->version(),
            'items' => [['marketplaceSku' => '101', 'totalQuantity' => 1, 'priceMinor' => 100]],
        ], \JSON_THROW_ON_ERROR));
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('PUT', "{$base}/days", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'version' => $foreignPlan->version(),
            'marketplaceSku' => '101',
            'date' => '2026-07-01',
            'quantity' => 1,
            'priceMinor' => 100,
        ], \JSON_THROW_ON_ERROR));
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('DELETE', "{$base}/skus/101?version={$foreignPlan->version()}");
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('GET', "/api/companies/{$company->id()->toRfc4122()}/sales-plans");
        self::assertSame([], $this->json($client)['items']);
    }

    public function testForeignAccountCannotBePlannedNorBrowsed(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $foreignAccount = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withExternalShopId('shop-'.Uuid::v7()->toRfc4122())
            ->persistWith($this->companies(), $this->accounts());
        $base = "/api/companies/{$company->id()->toRfc4122()}/sales-plans";

        $client->request('POST', $base, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'marketplaceAccountId' => $foreignAccount->id()->toRfc4122(),
            'name' => 'Чужой кабинет',
            'dateFrom' => '2026-07-01',
            'dateTo' => '2026-07-31',
            'currency' => 'RUB',
        ], \JSON_THROW_ON_ERROR));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('marketplace_account_not_found', $this->json($client)['code']);
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedSkuBodies')]
    public function testRejectsMalformedSkuInputAtTheBoundary(array $body, string $code): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $plan = SalesPlanBuilder::aSalesPlan()->withCompanyId($company->id())->persistWith($this->plans());

        $client->request(
            'PUT',
            "/api/companies/{$company->id()->toRfc4122()}/sales-plans/{$plan->id()->toRfc4122()}/skus",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame($code, $this->json($client)['code']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformedSkuBodies(): iterable
    {
        $item = ['marketplaceSku' => '101', 'totalQuantity' => 10, 'priceMinor' => 129_900];

        yield 'нет версии' => [['items' => [$item]], 'version_required'];
        yield 'дробная цена' => [['version' => 1, 'items' => [[...$item, 'priceMinor' => 1299.9]]], 'price_minor_invalid'];
        yield 'отрицательные штуки' => [['version' => 1, 'items' => [[...$item, 'totalQuantity' => -1]]], 'total_quantity_invalid'];
        yield 'пустой набор' => [['version' => 1, 'items' => []], 'items_required'];
        // Выручка плана считается в bigint: цена и штуки без потолка
        // записывались бы, а чтение итогов падало на переполнении.
        yield 'цена за потолком' => [['version' => 1, 'items' => [[...$item, 'priceMinor' => \PHP_INT_MAX]]], 'price_minor_invalid'];
        yield 'штуки за потолком' => [['version' => 1, 'items' => [[...$item, 'totalQuantity' => 2_147_483_648]]], 'total_quantity_invalid'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedDayBodies')]
    public function testRejectsMalformedDayEditAtTheBoundary(array $body, string $code): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $plan = SalesPlanBuilder::aSalesPlan()->withCompanyId($company->id())->persistWith($this->plans());

        $client->request(
            'PUT',
            "/api/companies/{$company->id()->toRfc4122()}/sales-plans/{$plan->id()->toRfc4122()}/days",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame($code, $this->json($client)['code']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformedDayBodies(): iterable
    {
        $day = ['version' => 1, 'marketplaceSku' => '101', 'date' => '2026-07-01', 'quantity' => 1, 'priceMinor' => 100];

        yield 'нет версии' => [array_diff_key($day, ['version' => true]), 'version_required'];
        yield 'несуществующая дата' => [[...$day, 'date' => '2026-02-30'], 'date_invalid'];
        yield 'дробная цена' => [[...$day, 'priceMinor' => 1.5], 'price_minor_invalid'];
        yield 'штуки за потолком' => [[...$day, 'quantity' => 2_000_000], 'quantity_invalid'];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function loginAsCompanyMember(KernelBrowser $client): Company
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $users = new DoctrineUserRepository($entityManager);
        $companyMembers = new DoctrineCompanyMemberRepository($entityManager);

        $company = CompanyBuilder::aCompany()->persistWith($this->companies());
        $user = UserBuilder::aUser()->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($company)
            ->withUser($user)
            ->persistWith($this->companies(), $users, $companyMembers);

        $client->loginUser($user, 'api');

        return $company;
    }

    private function companies(): CompanyRepository
    {
        /** @var CompanyRepository $companies */
        $companies = static::getContainer()->get(CompanyRepository::class);

        return $companies;
    }

    private function accounts(): MarketplaceAccountRepository
    {
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = static::getContainer()->get(MarketplaceAccountRepository::class);

        return $accounts;
    }

    private function plans(): SalesPlanRepository
    {
        /** @var SalesPlanRepository $plans */
        $plans = static::getContainer()->get(SalesPlanRepository::class);

        return $plans;
    }
}
