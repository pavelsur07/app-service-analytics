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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ListBuyoutRatesControllerTest extends WebTestCase
{
    public function testEmptyPeriodReturnsUnknownSummaryRates(): void
    {
        $client = self::createClient();
        $companyId = $this->loginAsCompanyMember($client);

        $client->request('GET', '/api/companies/'.$companyId->toRfc4122().'/buyout-rate?days=7');

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame([], $this->items($payload));
        self::assertSame([
            'orderedQuantity' => 0,
            'resolvedQuantity' => 0,
            'projectedBuyoutQuantity' => null,
            'projectedBuyoutRateBps' => null,
            'resolutionRateBps' => null,
        ], $payload['summary']);
    }

    public function testPaginatesWithoutGapsAndKeepsFullSummaryAcrossPages(): void
    {
        $client = self::createClient();
        $companyId = $this->loginAsCompanyMember($client);
        $accountId = Uuid::v7();
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow'));
        $this->sales()->upsertAll([
            $this->pending($companyId, $accountId, 'SKU-A', 1, $today),
            $this->pending($companyId, $accountId, 'SKU-B', 2, $today),
            $this->pending($companyId, $accountId, 'SKU-C', 3, $today),
            $this->pending(Uuid::v7(), Uuid::v7(), 'SKU-FOREIGN', 100, $today),
        ]);

        $client->request('GET', '/api/companies/'.$companyId->toRfc4122().'/buyout-rate?days=30&limit=2');
        self::assertResponseIsSuccessful();
        $first = $this->payload($client);
        self::assertSame(['SKU-A', 'SKU-B'], array_column($this->items($first), 'marketplaceSku'));
        self::assertSame([
            'orderedQuantity' => 6,
            'resolvedQuantity' => 0,
            'projectedBuyoutQuantity' => null,
            'projectedBuyoutRateBps' => null,
            'resolutionRateBps' => 0,
        ], $first['summary']);
        self::assertIsString($first['nextCursor']);

        $client->request('GET', '/api/companies/'.$companyId->toRfc4122().'/buyout-rate?days=30&limit=2&cursor='.urlencode($first['nextCursor']));
        self::assertResponseIsSuccessful();
        $second = $this->payload($client);
        self::assertSame(['SKU-C'], array_column($this->items($second), 'marketplaceSku'));
        self::assertSame($first['summary'], $second['summary']);
        self::assertNull($second['nextCursor']);

        $item = $this->items($first)[0];
        self::assertSame(1, $item['orderedQuantity']);
        self::assertSame(0, $item['resolvedQuantity']);
        self::assertNull($item['projectedBuyoutRateBps']);
        self::assertSame(0, $item['resolutionRateBps']);
        self::assertSame('preliminary', $item['maturityStatus']);
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueryReturnsStable422(string $query, string $code): void
    {
        $client = self::createClient();
        $companyId = $this->loginAsCompanyMember($client);

        $client->request('GET', '/api/companies/'.$companyId->toRfc4122().'/buyout-rate?'.$query);

        self::assertResponseStatusCodeSame(422);
        $payload = $this->payload($client);
        self::assertSame(422, $payload['status']);
        self::assertSame($code, $payload['code']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'unsupported days' => ['days=14', 'invalid_days'];
        yield 'non-integer days' => ['days=month', 'invalid_days'];
        yield 'zero limit' => ['limit=0', 'invalid_limit'];
        yield 'limit above maximum' => ['limit=201', 'invalid_limit'];
        yield 'malformed cursor' => ['cursor=not-base64-%25%25%25', 'invalid_cursor'];
        yield 'NUL cursor' => ['cursor='.urlencode(base64_encode("\0")), 'invalid_cursor'];
    }

    public function testCursorLengthUsesDatabaseCharactersRatherThanUtf8Bytes(): void
    {
        $client = self::createClient();
        $companyId = $this->loginAsCompanyMember($client);
        $cursor = str_repeat('Я', 64);

        $client->request('GET', '/api/companies/'.$companyId->toRfc4122().'/buyout-rate?days=30&cursor='.urlencode(base64_encode($cursor)));

        self::assertResponseIsSuccessful();
    }

    public function testCompanyMemberCannotReadAnotherCompany(): void
    {
        $client = self::createClient();
        $this->loginAsCompanyMember($client);

        $client->request('GET', '/api/companies/'.Uuid::v7()->toRfc4122().'/buyout-rate?days=30');

        self::assertResponseStatusCodeSame(403);
    }

    private function pending(Uuid $companyId, Uuid $accountId, string $sku, int $quantity, \DateTimeImmutable $date): \App\Ingestion\Domain\SalesFact
    {
        return SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withSourceRowId('P-'.$sku.'|'.$sku)
            ->withPostingNumber('P-'.$sku)
            ->withOrderNumber('O-'.$sku)
            ->withMarketplaceSku($sku)
            ->withStatus('awaiting_packaging')
            ->withQuantity($quantity)
            ->withBusinessDate($date)
            ->build();
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

    private function sales(): SalesFactRepository
    {
        /** @var SalesFactRepository $repository */
        $repository = self::getContainer()->get(SalesFactRepository::class);

        return $repository;
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
     * @return list<array<string, mixed>>
     */
    private function items(array $payload): array
    {
        self::assertIsArray($payload['items']);
        /** @var list<array<string, mixed>> $items */
        $items = array_values(array_filter($payload['items'], \is_array(...)));

        return $items;
    }
}
