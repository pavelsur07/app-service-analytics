<?php

declare(strict_types=1);

namespace App\Tests\Functional\SalesPlanning;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\MarketplaceDayLoadRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\SalesPlanning\Domain\SalesPlanMetric;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceDayLoadBuilder;
use App\Tests\Support\Builder\SalesPlanBuilder;
use App\Tests\Support\Builder\SalesPlanLineBuilder;
use App\Tests\Support\Builder\UserBuilder;
use App\Tests\Support\SessionWebTestCase;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

#[SkipDatabaseRollback]
final class SalesPlanPerformanceControllerTest extends SessionWebTestCase
{
    public function testOriginalGoalPayloadPagingValidationAndTrustBoundary(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $companies = self::getContainer()->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);
        $plans = self::getContainer()->get(SalesPlanRepository::class);
        self::assertInstanceOf(SalesPlanRepository::class, $plans);
        $loads = self::getContainer()->get(MarketplaceDayLoadRepository::class);
        self::assertInstanceOf(MarketplaceDayLoadRepository::class, $loads);
        $company = CompanyBuilder::aCompany()->build();
        $user = UserBuilder::aUser()->build();
        $foreignCompany = Uuid::v7();
        try {
            $companies->add($company);
            $users = new DoctrineUserRepository($em);
            $users->add($user);
            CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith($companies, $users, new DoctrineCompanyMemberRepository($em));
            $base = SalesPlanBuilder::aSalesPlan()->withCompanyId($company->id())->withMetric(SalesPlanMetric::RetainedSales)->build();
            $lines = SalesPlanLineBuilder::linesOf($base)->withMarketplaceSku('BASE')->withTotalQuantity(31)->build();
            $base->approve($user->id(), new \DateTimeImmutable());
            $plans->add($base, $lines);
            $draft = SalesPlanBuilder::revisionOf($base)->build();
            $plans->add($draft, array_merge(
                SalesPlanLineBuilder::linesOf($draft)->withMarketplaceSku('NEW')->withTotalQuantity(999)->build(),
                SalesPlanLineBuilder::linesOf($draft)->withMarketplaceSku('BASE')->withTotalQuantity(999)->withPrice(\App\Shared\Domain\ValueObject\Money::ofMinor(777, 'RUB'))->build(),
            ));
            MarketplaceDayLoadBuilder::aMarketplaceDayLoad()->withCompanyId($company->id())->withMarketplaceAccountId($base->marketplaceAccountId())
                ->withReportType(MarketplaceReportType::OzonPostingFboList)->withPeriod(new \DateTimeImmutable('2026-07-10'))->completed()->persistWith($loads);
            $draftOnly = SalesPlanBuilder::aSalesPlan()->withCompanyId($company->id())->withMetric(SalesPlanMetric::RetainedSales)->build();
            $plans->add($draftOnly, []);
            $stale = SalesPlanBuilder::aSalesPlan()->withCompanyId($company->id())->withMetric(SalesPlanMetric::RetainedSales)->build();
            $staleLines = SalesPlanLineBuilder::linesOf($stale)->withMarketplaceSku('STALE')->build();
            $stale->approve($user->id(), new \DateTimeImmutable());
            $stale->supersede(new \DateTimeImmutable());
            $plans->add($stale, $staleLines);
            $legacy = SalesPlanBuilder::aSalesPlan()->withCompanyId($company->id())->withMetric(SalesPlanMetric::RetainedSales)->build();
            $legacyLines = SalesPlanLineBuilder::linesOf($legacy)->build();
            $legacy->approve($user->id(), new \DateTimeImmutable());
            $plans->add($legacy, $legacyLines);
            // Deliberate pre-feature legacy fixture, no production status write.
            $connection->executeStatement('UPDATE sales_plan SET metric = NULL WHERE company_id = ? AND id = ?', [$company->id()->toRfc4122(), $legacy->id()->toRfc4122()]);
            $foreign = SalesPlanBuilder::aSalesPlan()->withCompanyId($foreignCompany)->build();
            $plans->add($foreign, SalesPlanLineBuilder::linesOf($foreign)->build());
            $client->loginUser($user, 'api');
            $url = '/api/companies/'.$company->id()->toRfc4122().'/sales-plans/'.$draft->id()->toRfc4122().'/performance';
            $parameters = ['from' => '2026-07-10', 'to' => '2026-07-10', 'limit' => '1'];
            $client->request('GET', $url, $parameters);
            self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
            $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame($base->id()->toRfc4122(), self::path($body, 'baselinePlanId'));
            self::assertSame($draft->id()->toRfc4122(), self::path($body, 'currentPlanId'));
            self::assertSame('2026-07-01', self::path($body, 'accumulationFrom'));
            self::assertSame('BASE', self::path($body, 'skus', 0, 'marketplaceSku'));
            $value = self::path($body, 'skus', 0, 'points', 0, 'period');
            self::assertSame(1, self::path($value, 'planQuantity'));
            self::assertSame(['minor' => 129900, 'currency' => 'RUB'], self::path($value, 'planRevenueRrp'));
            self::assertSame(0, self::path($value, 'actualGoalQuantity'));
            self::assertSame(-1, self::path($value, 'quantityDeviation'));
            self::assertSame(['minor' => 0, 'currency' => 'RUB'], self::path($value, 'cohort', 'observedRevenue'));
            self::assertNull(self::path($value, 'expectedGoalQuantity'));
            self::assertNull(self::path($value, 'expectedAttainmentBps'));
            self::assertSame('not_assessed', self::path($body, 'returnsCoverage'));
            $validCursor = self::path($body, 'nextCursor');
            self::assertIsString($validCursor);
            $decodedCursor = base64_decode($validCursor, true);
            self::assertIsString($decodedCursor);
            $cursorPayload = json_decode($decodedCursor, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($cursorPayload);
            $cursorPayload['sku'] = "bad\0";
            $client->request('GET', $url, [...$parameters, 'cursor' => base64_encode(json_encode($cursorPayload, \JSON_THROW_ON_ERROR))]);
            self::assertSame(422, $client->getResponse()->getStatusCode(), 'A matching cursor scope must not allow a NUL SKU into PostgreSQL.');
            $client->request('GET', $url, [...$parameters, 'cursor' => self::path($body, 'nextCursor')]);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $next = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame('NEW', self::path($next, 'skus', 0, 'marketplaceSku'));
            self::assertSame(0, self::path($next, 'skus', 0, 'points', 0, 'period', 'planQuantity'));
            self::assertNull(self::path($next, 'skus', 0, 'points', 0, 'period', 'attainmentBps'));
            self::assertNull(self::path($next, 'nextCursor'));
            $client->request('GET', $url, ['from' => '2026-07-10', 'to' => '2026-07-31', 'groupBy' => 'period']);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $period = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            $periodPoints = self::path($period, 'skus', 0, 'points');
            self::assertIsArray($periodPoints);
            self::assertCount(1, $periodPoints);
            self::assertSame('2026-07-31', self::path($period, 'skus', 0, 'points', 0, 'bucketTo'));
            self::assertSame(31, self::path($period, 'skus', 0, 'points', 0, 'cumulative', 'planQuantity'));
            foreach ([
                ['from' => '2026-02-30'], ['from' => "2026-07-10\0"], ['from' => ['2026-07-10']], ['groupBy' => 'year'], ['limit' => '51'],
                ['limit' => '1.5'], ['limit' => ['1']], ['cursor' => 'garbage'], ['cursor' => ['bad']],
                ['accumulationFrom' => '2026-07-10'], ['marketplaceAccountId' => Uuid::v7()->toRfc4122()],
                ['baselinePlanId' => $draft->id()->toRfc4122()], ['from' => '2026-06-30'],
                ['to' => '2026-07-09'], ['from' => '2026-07-11', 'to' => '2026-07-11', 'cursor' => self::path($body, 'nextCursor')],
            ] as $bad) {
                $client->request('GET', $url, array_replace($parameters, $bad));
                self::assertSame(422, $client->getResponse()->getStatusCode(), json_encode($bad, \JSON_THROW_ON_ERROR));
            }
            foreach ([[$draftOnly->id(), 409], [$stale->id(), 409], [$legacy->id(), 422]] as [$id, $status]) {
                $client->request('GET', '/api/companies/'.$company->id()->toRfc4122().'/sales-plans/'.$id->toRfc4122().'/performance', $parameters);
                self::assertSame($status, $client->getResponse()->getStatusCode());
            }
            $client->request('GET', '/api/companies/'.$foreignCompany->toRfc4122().'/sales-plans/'.$draft->id()->toRfc4122().'/performance', $parameters);
            self::assertSame(403, $client->getResponse()->getStatusCode());
            $client->request('GET', '/api/companies/'.$company->id()->toRfc4122().'/sales-plans/'.$foreign->id()->toRfc4122().'/performance', $parameters);
            self::assertSame(404, $client->getResponse()->getStatusCode());
        } finally {
            foreach (['sales_plan_day_claim', 'sales_plan_line', 'sales_plan', 'marketplace_day_load', 'company_member', 'company'] as $table) {
                $column = 'company' === $table ? 'id' : 'company_id';
                foreach ([$company->id()->toRfc4122(), $foreignCompany->toRfc4122()] as $companyId) {
                    $connection->executeStatement('DELETE FROM '.$table.' WHERE '.$column.' = ?', [$companyId]);
                }
            }
            $connection->executeStatement('DELETE FROM "user" WHERE id = ?', [$user->id()->toRfc4122()]);
        }
    }

    private static function path(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }
}
