<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ShowSkuForecastFactControllerTest extends WebTestCase
{
    public function testReturnsPastCalendarDaysDescendingWithZerosAndTenantIsolation(): void
    {
        $client = self::createClient();
        $companyId = $this->login($client);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));
        $day = $today->modify('first day of this month');
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([
            $this->sale($companyId, 'SKU', $day, 2, 12345),
            $this->sale($companyId, 'OTHER', $day, 4, 99999),
            $this->sale(Uuid::v7(), 'SKU', $day, 8, 99999),
        ]);

        $client->request('GET', '/api/companies/'.$companyId->toRfc4122().'/sku-forecast-fact?sku=SKU&month='.$today->format('Y-m'));

        self::assertResponseIsSuccessful();
        $payload = self::payload($client);
        self::assertSame('SKU', $payload['marketplaceSku']);
        self::assertSame('RUB', $payload['currency']);
        self::assertSame($today->format('Y-m'), $payload['month']);
        self::assertCount((int) $today->format('j'), $payload['days']);
        self::assertSame($today->format('Y-m-d'), $payload['days'][0]['date']);
        self::assertSame($day->format('Y-m-d'), $payload['days'][\count($payload['days']) - 1]['date']);
        $first = $payload['days'][\count($payload['days']) - 1];
        self::assertSame(2, $first['orderedQuantity']);
        self::assertSame(24690, $first['orderedAmountMinor']);
        self::assertSame(0, $first['actualRevenueMinor']);
        self::assertNull($first['forecastRevenueMinor']);
        if (\count($payload['days']) > 1) {
            self::assertSame(0, $payload['days'][0]['orderedQuantity']);
            self::assertNull($payload['days'][0]['plannedBuyoutRateBps']);
        }
    }

    public function testInvalidParametersAndCompanyAccess(): void
    {
        $client = self::createClient();
        $companyId = $this->login($client);
        $path = '/api/companies/'.$companyId->toRfc4122().'/sku-forecast-fact';
        foreach (['?sku=SKU&month=2026-13', '?sku=SKU&month=9999-01', '?month=2026-01', '?sku=%00'] as $query) {
            $client->request('GET', $path.$query);
            self::assertResponseStatusCodeSame(422);
        }
        $client->request('GET', '/api/companies/'.Uuid::v7()->toRfc4122().'/sku-forecast-fact?sku=SKU');
        self::assertResponseStatusCodeSame(403);
    }

    public function testEmptyLeapMonthIncludesEveryCalendarDay(): void
    {
        $client = self::createClient();
        $companyId = $this->login($client);

        $client->request('GET', '/api/companies/'.$companyId->toRfc4122().'/sku-forecast-fact?sku=MISSING&month=2024-02');

        self::assertResponseIsSuccessful();
        $payload = self::payload($client);
        self::assertCount(29, $payload['days']);
        self::assertSame('2024-02-29', $payload['days'][0]['date']);
        self::assertSame('2024-02-01', $payload['days'][28]['date']);
        self::assertSame(0, $payload['days'][0]['orderedAmountMinor']);
        self::assertNull($payload['days'][0]['forecastRevenueMinor']);
    }

    private function sale(Uuid $companyId, string $sku, \DateTimeImmutable $day, int $quantity, int $minor): \App\Ingestion\Domain\SalesFact
    {
        $id = Uuid::v7()->toRfc4122();

        return SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId(Uuid::v7())
            ->withSourceRowId($id.'|'.$sku)
            ->withPostingNumber($id)
            ->withOrderNumber($id)
            ->withMarketplaceSku($sku)
            ->withStatus('awaiting_packaging')
            ->withBusinessDate($day)
            ->withQuantity($quantity)
            ->withAmount(Money::ofMinor($minor, 'RUB'))
            ->build();
    }

    private function login(KernelBrowser $client): Uuid
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

    /**
     * @return array{marketplaceSku: string, currency: string, month: string, days: list<array{date: string, orderedQuantity: int, orderedAmountMinor: int, actualRevenueMinor: int, forecastRevenueMinor: ?int, plannedBuyoutRateBps: ?int}>}
     */
    private static function payload(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        /** @var array{marketplaceSku: string, currency: string, month: string, days: list<array{date: string, orderedQuantity: int, orderedAmountMinor: int, actualRevenueMinor: int, forecastRevenueMinor: ?int, plannedBuyoutRateBps: ?int}>} $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $payload;
    }
}
