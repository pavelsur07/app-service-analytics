<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class SearchSkuForecastFactSkusControllerTest extends WebTestCase
{
    public function testSearchesCatalogFieldsAndHistoricalSkusWithoutLeakingOtherCompanies(): void
    {
        $client = self::createClient();
        $companyId = $this->loginAsCompanyMember($client);
        $otherCompanyId = Uuid::v7();
        $accountId = Uuid::v7();
        $listings = self::getContainer()->get(MarketplaceListingRepository::class);
        self::assertInstanceOf(MarketplaceListingRepository::class, $listings);
        $listings->replaceForAccount($companyId->toRfc4122(), $accountId, [
            MarketplaceListingBuilder::aMarketplaceListing()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)->withMarketplaceSku('SKU-101')->withName('Красная чашка')->withOfferId('CUP-RED')->build(),
            MarketplaceListingBuilder::aMarketplaceListing()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)->withMarketplaceSku('SKU-102')->withName('Синяя чашка')->withOfferId('CUP-BLUE')->build(),
        ]);
        $secondAccountId = Uuid::v7();
        $listings->replaceForAccount($companyId->toRfc4122(), $secondAccountId, [
            MarketplaceListingBuilder::aMarketplaceListing()->withCompanyId($companyId)->withMarketplaceAccountId($secondAccountId)->withMarketplaceSku('SKU-101')->withName('Красная чашка')->withOfferId('CUP-RED')->build(),
        ]);
        $otherAccountId = Uuid::v7();
        $listings->replaceForAccount($otherCompanyId->toRfc4122(), $otherAccountId, [
            MarketplaceListingBuilder::aMarketplaceListing()->withCompanyId($otherCompanyId)->withMarketplaceAccountId($otherAccountId)->withMarketplaceSku('SKU-999')->withName('Красная чашка')->build(),
        ]);

        $sales = self::getContainer()->get(SalesFactRepository::class);
        self::assertInstanceOf(SalesFactRepository::class, $sales);
        $sales->upsertAll([
            SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)->withMarketplaceSku('CUP-OLD')->withSourceRowId('old-1')->build(),
            SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)->withMarketplaceSku('AB')->withSourceRowId('short-1')->build(),
            SalesFactBuilder::aSalesFact()->withCompanyId($otherCompanyId)->withMarketplaceAccountId($otherAccountId)->withMarketplaceSku('CUP-SECRET')->withSourceRowId('secret-1')->build(),
        ]);

        $client->request('GET', $this->url($companyId, 'q='.urlencode('красная')));
        self::assertResponseIsSuccessful();
        self::assertSame([['marketplaceSku' => 'SKU-101', 'name' => 'Красная чашка', 'offerId' => 'CUP-RED']], $this->payload($client)['items']);

        $client->request('GET', $this->url($companyId, 'q=CUP'));
        self::assertResponseIsSuccessful();
        $items = $this->items($this->payload($client));
        self::assertSame(['CUP-OLD', 'SKU-101', 'SKU-102'], array_column($items, 'marketplaceSku'));
        self::assertSame(['marketplaceSku' => 'CUP-OLD', 'name' => null, 'offerId' => null], $items[0]);

        $client->request('GET', $this->url($companyId, 'q=A'));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->items($this->payload($client)));

        $client->request('GET', $this->url($companyId, 'q=AB'));
        self::assertResponseIsSuccessful();
        self::assertSame(['AB'], array_column($this->items($this->payload($client)), 'marketplaceSku'));

        $client->request('GET', $this->url($companyId, ''));
        self::assertResponseIsSuccessful();
        self::assertSame(['SKU-101', 'SKU-102'], array_column($this->items($this->payload($client)), 'marketplaceSku'));

        $client->request('GET', $this->url($companyId, 'limit=2'));
        self::assertResponseIsSuccessful();
        self::assertSame(['SKU-101', 'SKU-102'], array_column($this->items($this->payload($client)), 'marketplaceSku'));
        self::assertNull($this->payload($client)['nextCursor']);
    }

    public function testPaginatesDistinctSkusWithCursorBoundToSearch(): void
    {
        $client = self::createClient();
        $companyId = $this->loginAsCompanyMember($client);
        $accountId = Uuid::v7();
        $sales = self::getContainer()->get(SalesFactRepository::class);
        self::assertInstanceOf(SalesFactRepository::class, $sales);
        $facts = [];
        foreach (['SKU-A-1', 'SKU-A-2', 'SKU-A-3'] as $sku) {
            $facts[] = SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)->withMarketplaceSku($sku)->withSourceRowId('row-'.$sku)->build();
        }
        $sales->upsertAll($facts);

        $client->request('GET', $this->url($companyId, 'q=SKU-A-&limit=2'));
        self::assertResponseIsSuccessful();
        $first = $this->payload($client);
        self::assertSame(['SKU-A-1', 'SKU-A-2'], array_column($this->items($first), 'marketplaceSku'));
        self::assertIsString($first['nextCursor']);

        $client->request('GET', $this->url($companyId, 'q=SKU-A-&limit=2&cursor='.urlencode($first['nextCursor'])));
        self::assertResponseIsSuccessful();
        $second = $this->payload($client);
        self::assertSame(['SKU-A-3'], array_column($this->items($second), 'marketplaceSku'));
        self::assertNull($second['nextCursor']);

        $client->request('GET', $this->url($companyId, 'q=B-&cursor='.urlencode($first['nextCursor'])));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_cursor', $this->payload($client)['code']);
    }

    public function testRejectsInvalidParametersAndCompanyAccess(): void
    {
        $client = self::createClient();
        $companyId = $this->loginAsCompanyMember($client);
        foreach (['q=x&limit=201', 'q=x&limit=1.5', 'q=x&limit%5B%5D=2', 'q=x&cursor=broken', 'q%5B%5D=x'] as $query) {
            $client->request('GET', $this->url($companyId, $query));
            self::assertResponseStatusCodeSame(422);
        }

        $client->request('GET', $this->url($companyId, ''));
        self::assertResponseIsSuccessful();

        $client->request('GET', $this->url($companyId, 'q=x&limit=200'));
        self::assertResponseIsSuccessful();

        $client->request('GET', $this->url(Uuid::v7(), 'q=x'));
        self::assertResponseStatusCodeSame(403);
    }

    private function loginAsCompanyMember(KernelBrowser $client): Uuid
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var CompanyRepository $companies */
        $companies = self::getContainer()->get(CompanyRepository::class);
        $users = new DoctrineUserRepository($entityManager);
        $members = new DoctrineCompanyMemberRepository($entityManager);
        $user = UserBuilder::aUser()->persistWith($users);
        $member = CompanyMemberBuilder::aCompanyMember()->withUser($user)->persistWith($companies, $users, $members);
        $client->loginUser($user, 'api');

        return $member->companyId();
    }

    private function url(Uuid $companyId, string $query): string
    {
        return '/api/companies/'.$companyId->toRfc4122().'/sku-forecast-fact/skus'.('' === $query ? '' : '?'.$query);
    }

    /** @return array<string, mixed> */
    private function payload(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array{marketplaceSku: string, name: ?string, offerId: ?string}>
     */
    private function items(array $payload): array
    {
        self::assertIsArray($payload['items']);
        $items = [];
        foreach ($payload['items'] as $item) {
            self::assertIsArray($item);
            self::assertIsString($item['marketplaceSku']);
            self::assertTrue(null === $item['name'] || \is_string($item['name']));
            self::assertTrue(null === $item['offerId'] || \is_string($item['offerId']));
            $items[] = ['marketplaceSku' => $item['marketplaceSku'], 'name' => $item['name'], 'offerId' => $item['offerId']];
        }

        return $items;
    }
}
