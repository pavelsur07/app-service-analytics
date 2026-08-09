<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-005 (functional): изоляция арендаторов, формат ошибки. С PR4
 * (CompanyAccessSubscriber) маршрут требует и входа, и членства в запрошенной
 * компании — каждый тест логинится как участник компании, чей URL
 * запрашивает.
 */
final class ListSalesFactsControllerTest extends WebTestCase
{
    public function testReturnsOnlyFactsOfTheRequestedCompany(): void
    {
        $client = static::createClient();
        $salesFacts = $this->salesFacts();

        $companyA = $this->loginAsCompanyMember($client);
        // companyB не участвует ни в каком членстве — этот тест доказывает
        // изоляцию данных факт-таблицы, не проверку доступа, поэтому ей
        // не нужна ни Company, ни CompanyMember, только сам идентификатор.
        $companyB = Uuid::v7();

        $salesFacts->upsertAll([
            SalesFactBuilder::aSalesFact()->withCompanyId($companyA)->withSourceRowId('A-1|SKU-1')->build(),
            SalesFactBuilder::aSalesFact()->withCompanyId($companyB)->withSourceRowId('B-1|SKU-1')->build(),
        ]);

        $client->request('GET', \sprintf('/api/companies/%s/ingestion/ozon/sales-facts', $companyA->toRfc4122()));

        self::assertResponseIsSuccessful();
        $items = $this->itemsOf($this->decodeResponse($client));

        self::assertCount(1, $items);
        self::assertSame('A-1|SKU-1', $items[0]['sourceRowId']);
    }

    public function testLimitAboveMaximumIsRejectedWith422(): void
    {
        $client = static::createClient();
        $companyId = $this->loginAsCompanyMember($client);

        $client->request('GET', \sprintf('/api/companies/%s/ingestion/ozon/sales-facts?limit=201', $companyId->toRfc4122()));

        self::assertResponseStatusCodeSame(422);
        $payload = $this->decodeResponse($client);
        self::assertSame(422, $payload['status']);
        self::assertSame('invalid_limit', $payload['code']);
    }

    public function testMalformedCursorIsRejectedWith422(): void
    {
        $client = static::createClient();
        $companyId = $this->loginAsCompanyMember($client);

        $client->request('GET', \sprintf('/api/companies/%s/ingestion/ozon/sales-facts?cursor=not-valid-base64!!!', $companyId->toRfc4122()));

        self::assertResponseStatusCodeSame(422);
        $payload = $this->decodeResponse($client);
        self::assertSame('invalid_cursor', $payload['code']);
    }

    public function testCursorPaginatesThroughAllRowsWithoutOverlapOrGaps(): void
    {
        $client = static::createClient();
        $salesFacts = $this->salesFacts();

        $companyId = $this->loginAsCompanyMember($client);

        $facts = [];
        for ($i = 0; $i < 5; ++$i) {
            $facts[] = SalesFactBuilder::aSalesFact()
                ->withCompanyId($companyId)
                ->withSourceRowId(\sprintf('P-%d|SKU-1', $i))
                ->build();
        }
        $salesFacts->upsertAll($facts);

        /** @var list<string> $seenSourceRowIds */
        $seenSourceRowIds = [];
        $cursor = null;
        $pages = 0;
        do {
            $url = \sprintf('/api/companies/%s/ingestion/ozon/sales-facts?limit=2', $companyId->toRfc4122());
            if (null !== $cursor) {
                $url .= '&cursor='.urlencode($cursor);
            }
            $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $payload = $this->decodeResponse($client);

            foreach ($this->itemsOf($payload) as $item) {
                self::assertNotContains($item['sourceRowId'], $seenSourceRowIds, 'страницы не должны пересекаться');
                $seenSourceRowIds[] = $item['sourceRowId'];
            }

            $cursor = $this->nextCursorOf($payload);
            ++$pages;
            self::assertLessThan(10, $pages, 'пагинация не должна зацикливаться');
        } while (null !== $cursor);

        self::assertCount(5, $seenSourceRowIds, 'все строки должны быть возвращены ровно один раз без пропусков');
    }

    /**
     * Заводит пользователя и его членство через Builder (ADR-005), логинит
     * и возвращает companyId получившегося членства — вызывающий код сам
     * решает, какие sales_fact пометить этим companyId.
     */
    private function loginAsCompanyMember(KernelBrowser $client): Uuid
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var CompanyRepository $companies */
        $companies = static::getContainer()->get(CompanyRepository::class);
        $users = new DoctrineUserRepository($entityManager);
        $companyMembers = new DoctrineCompanyMemberRepository($entityManager);

        $user = UserBuilder::aUser()->persistWith($users);
        $member = CompanyMemberBuilder::aCompanyMember()->withUser($user)->persistWith($companies, $users, $companyMembers);

        $client->loginUser($user, 'api');

        return $member->companyId();
    }

    private function salesFacts(): SalesFactRepository
    {
        /** @var SalesFactRepository $salesFacts */
        $salesFacts = static::getContainer()->get(SalesFactRepository::class);

        return $salesFacts;
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

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array{sourceRowId: string}>
     */
    private function itemsOf(array $payload): array
    {
        self::assertIsArray($payload['items']);

        $items = [];
        foreach ($payload['items'] as $item) {
            self::assertIsArray($item);
            self::assertIsString($item['sourceRowId']);
            $items[] = ['sourceRowId' => $item['sourceRowId']];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function nextCursorOf(array $payload): ?string
    {
        $cursor = $payload['nextCursor'];
        self::assertTrue(null === $cursor || \is_string($cursor));

        return $cursor;
    }
}
