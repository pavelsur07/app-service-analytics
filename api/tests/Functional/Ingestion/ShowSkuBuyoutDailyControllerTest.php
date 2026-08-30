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

final class ShowSkuBuyoutDailyControllerTest extends WebTestCase
{
    public function testReturnsRequestedSkuDatesAscendingAndExcludesOtherSkuAndCompany(): void
    {
        $client = self::createClient();
        $companyId = $this->loginAsCompanyMember($client);
        $accountId = Uuid::v7();
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->setTime(0, 0);
        $sku = 'SKU % тест';
        $this->sales()->upsertAll([
            $this->pending($companyId, $accountId, $sku, $today->modify('-1 day')),
            $this->pending($companyId, $accountId, $sku, $today),
            $this->pending($companyId, $accountId, 'OTHER', $today),
            $this->pending(Uuid::v7(), Uuid::v7(), $sku, $today),
        ]);

        $client->request('GET', '/api/companies/'.$companyId->toRfc4122().'/buyout-rate/'.rawurlencode($sku).'/daily?days=7');

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame($sku, $payload['marketplaceSku']);
        self::assertIsArray($payload['series']);
        $firstPoint = $payload['series'][0] ?? null;
        self::assertIsArray($firstPoint);
        self::assertSame([
            $today->modify('-1 day')->format('Y-m-d'),
            $today->format('Y-m-d'),
        ], array_column($payload['series'], 'date'));
        self::assertNull($firstPoint['actualBuyoutRateBps']);
        self::assertNull($firstPoint['projectedBuyoutRateBps']);
        self::assertSame(0, $firstPoint['resolutionRateBps']);
    }

    public function testMissingSkuReturnsEmptySeriesWith200(): void
    {
        $client = self::createClient();
        $companyId = $this->loginAsCompanyMember($client);

        $client->request('GET', '/api/companies/'.$companyId->toRfc4122().'/buyout-rate/MISSING/daily?days=30');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload($client)['series']);
    }

    public function testInvalidDaysReturnsStable422(): void
    {
        $client = self::createClient();
        $companyId = $this->loginAsCompanyMember($client);

        $client->request('GET', '/api/companies/'.$companyId->toRfc4122().'/buyout-rate/SKU/daily?days=14');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_days', $this->payload($client)['code']);
    }

    public function testSkuAllows64Utf8CharactersAndRejects65(): void
    {
        $client = self::createClient();
        $companyId = $this->loginAsCompanyMember($client);

        $client->request('GET', '/api/companies/'.$companyId->toRfc4122().'/buyout-rate/'.rawurlencode(str_repeat('Я', 64)).'/daily?days=7');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/companies/'.$companyId->toRfc4122().'/buyout-rate/'.rawurlencode(str_repeat('Я', 65)).'/daily?days=7');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_sku', $this->payload($client)['code']);
    }

    private function pending(Uuid $companyId, Uuid $accountId, string $sku, \DateTimeImmutable $date): \App\Ingestion\Domain\SalesFact
    {
        return SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withSourceRowId(Uuid::v7()->toRfc4122().'|'.$sku)
            ->withPostingNumber(Uuid::v7()->toRfc4122())
            ->withOrderNumber(Uuid::v7()->toRfc4122())
            ->withMarketplaceSku($sku)
            ->withStatus('awaiting_packaging')
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
}
