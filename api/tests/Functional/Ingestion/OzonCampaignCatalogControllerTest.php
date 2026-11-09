<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\OzonAdvertisingFetcher;
use App\Ingestion\Domain\OzonCampaignCatalogData;
use App\Ingestion\Domain\OzonCampaignCatalogItem;
use App\Ingestion\Domain\OzonCampaignCatalogRepository;
use App\Ingestion\Domain\RawDocumentStorage;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\OzonCampaignCatalogSnapshotBuilder;
use App\Tests\Support\Builder\UserBuilder;
use App\Tests\Support\SessionWebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

final class OzonCampaignCatalogControllerTest extends SessionWebTestCase
{
    public function testCatalogIsVisibleWithoutObservationAndDoesNotReadExternalSystems(): void
    {
        [$client, $company] = $this->fixture();
        $fetcher = self::createMock(OzonAdvertisingFetcher::class);
        foreach (get_class_methods(OzonAdvertisingFetcher::class) as $method) {
            $fetcher->expects(self::never())->method($method);
        }
        self::getContainer()->set(OzonAdvertisingFetcher::class, $fetcher);
        $storage = self::createMock(RawDocumentStorage::class);
        $storage->expects(self::never())->method('get');
        self::getContainer()->set(RawDocumentStorage::class, $storage);
        $repository = self::getContainer()->get(OzonCampaignCatalogRepository::class);
        self::assertInstanceOf(OzonCampaignCatalogRepository::class, $repository);
        $snapshot = OzonCampaignCatalogSnapshotBuilder::aSnapshot()->withCompany(Uuid::fromString($company))->persistWith($repository);
        $url = '/api/companies/'.$company.'/ozon-advertising/campaigns?accountId='.$snapshot->accountId()->toRfc4122();
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        self::assertSame('ready', $data['status']);
        self::assertSame([['id' => '123', 'title' => 'Тестовая кампания', 'state' => 'CAMPAIGN_STATE_RUNNING', 'advObjectType' => 'SKU', 'paymentType' => 'CPC']], $data['items']);
        self::assertIsString($data['receivedAt']);
        $client->request('GET', '/api/companies/'.Uuid::v7()->toRfc4122().'/ozon-advertising/campaigns?accountId='.$snapshot->accountId()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/api/companies/'.$company.'/ozon-advertising/campaigns?accountId='.Uuid::v7()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSame('not_loaded', $this->json($client)['status']);
    }

    public function testPagesAndSnapshotChangeCannotMixDataAndInvalidInputsAreRejected(): void
    {
        [$client, $company] = $this->fixture();
        $repository = self::getContainer()->get(OzonCampaignCatalogRepository::class);
        self::assertInstanceOf(OzonCampaignCatalogRepository::class, $repository);
        $data = new OzonCampaignCatalogData([new OzonCampaignCatalogItem('1', 'One', 'CAMPAIGN_STATE_RUNNING', 'SKU', 'CPC'), new OzonCampaignCatalogItem('2', 'Two', 'CAMPAIGN_STATE_RUNNING', 'SKU', 'CPC')], 2, true);
        $base = OzonCampaignCatalogSnapshotBuilder::aSnapshot()->withCompany(Uuid::fromString($company))->withData($data);
        $snapshot = $base->persistWith($repository);
        $url = '/api/companies/'.$company.'/ozon-advertising/campaigns?accountId='.$snapshot->accountId()->toRfc4122().'&limit=1';
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $cursor = $this->json($client)['nextCursor'];
        self::assertIsString($cursor);
        $client->request('GET', $url.'&cursor='.rawurlencode($cursor));
        self::assertResponseIsSuccessful();
        self::assertNull($this->json($client)['nextCursor']);
        $base->withRaw(Uuid::v7())->withReceivedAt(new \DateTimeImmutable('2026-11-09T11:00:00Z'))->persistWith($repository);
        $client->request('GET', $url.'&cursor='.rawurlencode($cursor));
        self::assertResponseStatusCodeSame(409);
        foreach (['&limit=201', '&cursor=bad', '&includeArchived=unknown'] as $invalid) {
            $client->request('GET', $url.$invalid);
            self::assertResponseStatusCodeSame(422);
        }
    }

    public function testDefaultPageIsFiftyAndLargerAllowedPageIsBounded(): void
    {
        [$client, $company] = $this->fixture();
        $repository = self::getContainer()->get(OzonCampaignCatalogRepository::class);
        self::assertInstanceOf(OzonCampaignCatalogRepository::class, $repository);
        $items = array_map(static fn (int $id): OzonCampaignCatalogItem => new OzonCampaignCatalogItem((string) $id, 'Campaign', 'CAMPAIGN_STATE_RUNNING', 'SKU', 'CPC'), range(1, 51));
        $snapshot = OzonCampaignCatalogSnapshotBuilder::aSnapshot()->withCompany(Uuid::fromString($company))->withData(new OzonCampaignCatalogData($items, 51, true))->persistWith($repository);
        $url = '/api/companies/'.$company.'/ozon-advertising/campaigns?accountId='.$snapshot->accountId()->toRfc4122();
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $response = $this->json($client);
        self::assertIsArray($response['items']);
        self::assertCount(50, $response['items']);
        self::assertIsString($response['nextCursor']);
        $client->request('GET', $url.'&limit=150');
        self::assertResponseIsSuccessful();
        $response = $this->json($client);
        self::assertIsArray($response['items']);
        self::assertCount(51, $response['items']);
        self::assertNull($response['nextCursor']);
    }

    /** @return array{KernelBrowser,string} */
    private function fixture(): array
    {
        $client = self::createClient();
        $client->disableReboot();
        $c = self::getContainer();
        $companies = $c->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);
        $users = $c->get(DoctrineUserRepository::class);
        self::assertInstanceOf(DoctrineUserRepository::class, $users);
        $members = $c->get(DoctrineCompanyMemberRepository::class);
        self::assertInstanceOf(DoctrineCompanyMemberRepository::class, $members);
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $user = UserBuilder::aUser()->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith($companies, $users, $members);
        $client->loginUser($user, 'api');

        return [$client, $company->id()->toRfc4122()];
    }

    /** @return array<string,mixed> */
    private function json(KernelBrowser $client): array
    {
        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        $value = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($value);
        self::assertFalse(array_is_list($value));

        return $value;
    }
}
