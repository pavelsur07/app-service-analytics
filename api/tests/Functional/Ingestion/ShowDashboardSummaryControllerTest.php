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
use App\Tests\Support\SessionWebTestCase as WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

/**
 * Через HTTP — то, что иначе не проверить (CLAUDE.md §9): изоляция
 * арендаторов живёт в подписчике `kernel.controller`, а разбор периода —
 * на границе доверия.
 */
final class ShowDashboardSummaryControllerTest extends WebTestCase
{
    public function testReturnsOnlyOwnCompanyFactsForBothPeriods(): void
    {
        $client = self::createClient();
        $companyId = $this->login($client);
        $yesterday = new \DateTimeImmutable('yesterday', new \DateTimeZone('Europe/Moscow'));
        $from = $yesterday->modify('-6 days');
        $compareTo = $from->modify('-1 day');
        $compareFrom = $compareTo->modify('-6 days');

        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([
            $this->sale($companyId, $yesterday, 2, 20_000),
            $this->sale($companyId, $compareTo, 1, 10_000),
            $this->sale(Uuid::v7(), $yesterday, 9, 900_000),
        ]);

        $client->request('GET', $this->path($companyId, $from, $yesterday, $compareFrom, $compareTo));

        self::assertResponseIsSuccessful();
        $payload = self::payload($client);
        self::assertSame('RUB', $payload['currency'] ?? null);
        self::assertSame($from->format('Y-m-d'), $payload['current']['from']);
        self::assertSame(20_000, $payload['current']['metrics']['ordersAmountMinor']);
        self::assertSame(2, $payload['current']['metrics']['ordersQuantity']);
        self::assertSame(10_000, $payload['comparison']['metrics']['ordersAmountMinor']);
        self::assertCount(7, $payload['current']['days']);
        self::assertSame(20_000, ($payload['current']['days'][6] ?? null)['ordersAmountMinor'] ?? null);
        // Загрузок не было — сравнивать нельзя, хоть суммы и есть.
        self::assertNull($payload['changes']['ordersAmountBps']);
    }

    public function testForeignCompanyIsForbidden(): void
    {
        $client = self::createClient();
        $this->login($client);
        $yesterday = new \DateTimeImmutable('yesterday', new \DateTimeZone('Europe/Moscow'));

        $client->request('GET', $this->path(Uuid::v7(), $yesterday, $yesterday, $yesterday->modify('-1 day'), $yesterday->modify('-1 day')));

        self::assertResponseStatusCodeSame(403);
    }

    public function testRejectsInvalidPeriods(): void
    {
        $client = self::createClient();
        $companyId = $this->login($client);
        $base = '/api/companies/'.$companyId->toRfc4122().'/dashboard/summary';
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');

        $cases = [
            'invalid_period' => [
                '?to=2026-08-14&compareFrom=2026-08-01&compareTo=2026-08-07',
                '?from=2026-02-30&to=2026-03-05&compareFrom=2026-02-01&compareTo=2026-02-05',
                '?from=2026-08-14&to=2026-08-08&compareFrom=2026-08-01&compareTo=2026-08-07',
                '?from=14.08.2026&to=2026-08-14&compareFrom=2026-08-01&compareTo=2026-08-07',
                '?from[]=2026-08-08&to=2026-08-14&compareFrom=2026-08-01&compareTo=2026-08-07',
                '?from='.$today.'&to='.$today.'&compareFrom=2026-08-01&compareTo=2026-08-07',
                '?from=2019-12-31&to=2020-01-05&compareFrom=2019-12-01&compareTo=2019-12-05',
                // 367 дней — больше года с високосным днём.
                '?from=2024-01-01&to=2025-01-01&compareFrom=2023-01-01&compareTo=2023-12-31',
            ],
            'invalid_comparison' => [
                '?from=2026-08-08&to=2026-08-14',
                // Пересечение: общие дни молча ушли бы в выбранный период.
                '?from=2026-08-08&to=2026-08-14&compareFrom=2026-08-01&compareTo=2026-08-08',
                '?from=2026-08-08&to=2026-08-14&compareFrom=2026-08-07&compareTo=2026-08-01',
                // Год 0000 PHP разбирает, а PostgreSQL — нет: 422, не 500.
                '?from=2026-08-08&to=2026-08-14&compareFrom=0000-01-01&compareTo=0000-01-07',
            ],
        ];

        foreach ($cases as $code => $queries) {
            foreach ($queries as $query) {
                $client->request('GET', $base.$query);
                self::assertResponseStatusCodeSame(422, $query);
                self::assertSame($code, self::payload($client)['code'] ?? null, $query);
            }
        }

        // Сравнение «год назад» от периода, начатого в 2020-м, уходит
        // раньше нижней границы — там просто нет данных.
        $client->request('GET', $base.'?from=2020-01-01&to=2020-01-07&compareFrom=2019-01-01&compareTo=2019-01-07');
        self::assertResponseIsSuccessful();

        // Ровно 366 дней — високосный год целиком — допустим.
        $client->request('GET', $base.'?from=2024-01-01&to=2024-12-31&compareFrom=2023-01-01&compareTo=2023-12-31');
        self::assertResponseIsSuccessful();
    }

    private function path(Uuid $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $compareFrom, \DateTimeImmutable $compareTo): string
    {
        return '/api/companies/'.$companyId->toRfc4122().'/dashboard/summary?'.http_build_query([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'compareFrom' => $compareFrom->format('Y-m-d'),
            'compareTo' => $compareTo->format('Y-m-d'),
        ]);
    }

    private function sale(Uuid $companyId, \DateTimeImmutable $day, int $quantity, int $minor): \App\Ingestion\Domain\SalesFact
    {
        $id = Uuid::v7()->toRfc4122();

        return SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId(Uuid::v7())
            ->withSourceRowId($id.'|SKU')
            ->withPostingNumber($id)
            ->withOrderNumber($id)
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
     * @return array{currency?: string, code?: string, current: array{from: string, metrics: array{ordersAmountMinor: int, ordersQuantity: int}, days: list<array{ordersAmountMinor: int}>}, comparison: array{metrics: array{ordersAmountMinor: int}}, changes: array{ordersAmountBps: ?int}}
     */
    private static function payload(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        /** @var array{currency?: string, code?: string, current: array{from: string, metrics: array{ordersAmountMinor: int, ordersQuantity: int}, days: list<array{ordersAmountMinor: int}>}, comparison: array{metrics: array{ordersAmountMinor: int}}, changes: array{ordersAmountBps: ?int}} $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $payload;
    }
}
