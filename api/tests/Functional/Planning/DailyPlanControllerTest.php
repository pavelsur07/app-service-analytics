<?php

declare(strict_types=1);

namespace App\Tests\Functional\Planning;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DailyPlanControllerTest extends WebTestCase
{
    public function testRequestBoundaryRejectsInvalidPlanInput(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->account($company, 'shop-1');
        $this->listing($company, $account, 'SKU-1');
        $base = $this->base($company, $account, 'SKU-1');

        $invalid = $this->json($client, 'PUT', $base.'/2026-09-22', ['quantity' => 1, 'expectedVersion' => 0, 'extra' => true], 422);
        self::assertSame('request_fields_invalid', $invalid['code']);

        $tooLarge = $this->json($client, 'PUT', $base.'/2026-09-22', ['quantity' => 2_147_483_648, 'expectedVersion' => 0], 422);
        self::assertSame('request_invalid', $tooLarge['code']);

        $nullQuantity = $this->json($client, 'PUT', $base.'/2026-09-22', ['quantity' => null, 'expectedVersion' => 0], 422);
        self::assertSame('request_invalid', $nullQuantity['code']);

        $nullVersion = $this->json($client, 'DELETE', $base.'/2026-09-22', ['expectedVersion' => null], 422);
        self::assertSame('request_invalid', $nullVersion['code']);

        $exhaustedVersion = $this->json($client, 'DELETE', $base.'/2026-09-22', ['expectedVersion' => 2_147_483_647], 422);
        self::assertSame('request_invalid', $exhaustedVersion['code']);

        $incompletePeriod = $this->json($client, 'GET', $base.'?from=2026-01-01', null, 422);
        self::assertSame('request_invalid', $incompletePeriod['code']);

        $longPeriod = $this->json($client, 'GET', $base.'?from=2026-01-01&to=2026-04-01', null, 422);
        self::assertSame('period_invalid', $longPeriod['code']);

        $invalidDate = $this->json($client, 'DELETE', $base.'/2026-02-30', ['expectedVersion' => 0], 422);
        self::assertSame('date_invalid', $invalidDate['code']);

        $malformedDate = $this->json($client, 'PUT', $base.'/not-a-date', ['quantity' => 1, 'expectedVersion' => 0], 422);
        self::assertSame('date_invalid', $malformedDate['code']);
    }

    public function testCompanyAccountAndSkuScopesHaveStableErrors(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->account($company, 'shop-2');
        $this->listing($company, $account, 'SKU-1');

        $foreignCompany = CompanyBuilder::aCompany()->persistWith($this->companies());
        $foreignAccount = $this->account($foreignCompany, 'foreign-shop');

        $forbidden = $this->json($client, 'GET', $this->base($foreignCompany, $foreignAccount, 'SKU-1'), null, 403);
        self::assertSame('company_access_denied', $forbidden['code']);

        $missingAccount = $this->json($client, 'GET', $this->base($company, $foreignAccount, 'SKU-1'), null, 404);
        self::assertSame('marketplace_account_not_found', $missingAccount['code']);

        $unknownSku = $this->json($client, 'GET', $this->base($company, $account, 'UNKNOWN'), null, 422);
        self::assertSame('marketplace_sku_unknown', $unknownSku['code']);
    }

    public function testEncodedSlashInSkuReachesPlanningRoute(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->account($company, 'shop-with-slash-sku');
        $this->listing($company, $account, 'SKU/ONE');

        $payload = $this->json($client, 'GET', $this->base($company, $account, 'SKU/ONE'), null, 200);

        self::assertIsArray($payload['items']);
        self::assertCount(30, $payload['items']);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function json(KernelBrowser $client, string $method, string $url, ?array $body, int $status): array
    {
        $client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertSame($status, $client->getResponse()->getStatusCode());
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function base(Company $company, MarketplaceAccount $account, string $sku): string
    {
        return \sprintf('/api/companies/%s/planning/accounts/%s/skus/%s/plan', $company->id()->toRfc4122(), $account->id()->toRfc4122(), rawurlencode($sku));
    }

    private function account(Company $company, string $shop): MarketplaceAccount
    {
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = static::getContainer()->get(MarketplaceAccountRepository::class);

        return MarketplaceAccountBuilder::aMarketplaceAccount()->withCompany($company)->withExternalShopId($shop)
            ->persistWith($this->companies(), $accounts);
    }

    private function listing(Company $company, MarketplaceAccount $account, string $sku): void
    {
        /** @var MarketplaceListingRepository $listings */
        $listings = static::getContainer()->get(MarketplaceListingRepository::class);
        $listings->replaceForAccount(
            $company->id()->toRfc4122(), $account->id(),
            [MarketplaceListingBuilder::aMarketplaceListing()->withCompanyId($company->id())
                ->withMarketplaceAccountId($account->id())->withMarketplaceSku($sku)->build()],
        );
    }

    private function loginAsCompanyMember(KernelBrowser $client): Company
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $users = new DoctrineUserRepository($entityManager);
        $members = new DoctrineCompanyMemberRepository($entityManager);
        $company = CompanyBuilder::aCompany()->persistWith($this->companies());
        $user = UserBuilder::aUser()->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)
            ->persistWith($this->companies(), $users, $members);
        $client->loginUser($user, 'api');

        return $company;
    }

    private function companies(): CompanyRepository
    {
        /** @var CompanyRepository $companies */
        $companies = static::getContainer()->get(CompanyRepository::class);

        return $companies;
    }
}
