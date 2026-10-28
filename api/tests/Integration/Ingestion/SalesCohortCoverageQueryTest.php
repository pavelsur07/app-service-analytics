<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\MarketplaceDayLoadRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortCoverageQuery;
use App\Tests\Support\Builder\MarketplaceDayLoadBuilder;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SalesCohortCoverageQueryTest extends KernelTestCase
{
    public function testCompleteZeroMissingAndPartialHaveDifferentCoverage(): void
    {
        self::bootKernel();
        $company = Uuid::v7();
        $account = Uuid::v7();
        $day = new \DateTimeImmutable('2026-08-01');
        $mark = MarketplaceDayLoadBuilder::aMarketplaceDayLoad()
            ->withCompanyId($company)->withMarketplaceAccountId($account)
            ->withReportType(MarketplaceReportType::OzonPostingFboList);
        $mark->withPeriod($day)->completed()->persistWith($this->dayLoads());
        $mark->withPeriod($day->modify('+2 days'))->failed()->persistWith($this->dayLoads());
        // Legacy raw may have been saved before all pages were processed.
        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withCompanyId($company)->withMarketplaceAccountId($account)
            ->withPeriod($day->modify('+3 days'))
            ->persistWith(MarketplaceRawDocumentBuilder::repository(self::getContainer()));
        // Neither another tenant, another account, another report nor an
        // adjacent complete day proves this account's missing day complete.
        foreach ([
            $mark->withCompanyId(Uuid::v7()),
            $mark->withMarketplaceAccountId(Uuid::v7()),
            $mark->withReportType(MarketplaceReportType::OzonReturnsList),
        ] as $outside) {
            $outside->withPeriod($day->modify('+1 day'))->completed()->persistWith($this->dayLoads());
        }
        $raw = MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withCompanyId($company)->withMarketplaceAccountId($account)
            ->withPeriod($day->modify('+1 day'));
        foreach ([
            $raw->withCompanyId(Uuid::v7()),
            $raw->withMarketplaceAccountId(Uuid::v7()),
            $raw->withReportType(MarketplaceReportType::OzonReturnsList),
        ] as $outside) {
            $outside->persistWith(MarketplaceRawDocumentBuilder::repository(self::getContainer()));
        }
        $mark->withPeriod($day->modify('-1 day'))->completed()->persistWith($this->dayLoads());
        $mark->withPeriod($day->modify('+4 days'))->completed()->persistWith($this->dayLoads());

        $rows = $this->query()->build($company->toRfc4122(), $account->toRfc4122(), $day, $day->modify('+3 days'))
            ->executeQuery()->fetchAllAssociative();
        $coverage = array_map(SalesCohortCoverageQuery::mapRow(...), $rows);
        self::assertSame(['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04'], array_column($coverage, 'date'));
        self::assertSame(['complete', 'missing', 'partial', 'partial'], array_column($coverage, 'ordersCoverage'));
    }

    public function testFailedRefreshOverridesOldSuccessAndSuccessfulRetryRestoresCompleteness(): void
    {
        self::bootKernel();
        $company = Uuid::v7();
        $account = Uuid::v7();
        $day = new \DateTimeImmutable('2026-08-01');
        $mark = MarketplaceDayLoadBuilder::aMarketplaceDayLoad()
            ->withCompanyId($company)->withMarketplaceAccountId($account)
            ->withReportType(MarketplaceReportType::OzonPostingFboList)->withPeriod($day);
        $mark->withAt(new \DateTimeImmutable('2026-08-02 10:00:00'))->completed()->persistWith($this->dayLoads());
        $mark->withAt(new \DateTimeImmutable('2026-08-03 10:00:00'))->failed()->persistWith($this->dayLoads());
        self::assertSame('partial', $this->coverageStatus($company, $account, $day));
        $mark->withAt(new \DateTimeImmutable('2026-08-04 10:00:00'))->completed()->persistWith($this->dayLoads());
        self::assertSame('complete', $this->coverageStatus($company, $account, $day));
    }

    public function testWindowUsesCalendarDatesAndIncludesAll184Days(): void
    {
        self::bootKernel();
        $rows = $this->query()->build(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(),
            new \DateTimeImmutable('2026-03-01 23:59:59 America/New_York'),
            new \DateTimeImmutable('2026-08-31 00:00:00 Pacific/Auckland'),
        )->executeQuery()->fetchAllAssociative();
        self::assertCount(184, $rows);
        self::assertSame('2026-03-01', SalesCohortCoverageQuery::mapRow($rows[0])->date);
        self::assertSame('2026-08-31', SalesCohortCoverageQuery::mapRow($rows[183])->date);
    }

    #[DataProvider('invalidWindows')]
    public function testRejectsInvalidWindow(string $from, string $to): void
    {
        self::bootKernel();
        $this->expectException(\InvalidArgumentException::class);
        $this->query()->build(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidWindows(): iterable
    {
        yield 'reversed' => ['2026-08-02', '2026-08-01'];
        yield '185 days' => ['2026-03-01', '2026-09-01'];
    }

    private function coverageStatus(Uuid $company, Uuid $account, \DateTimeImmutable $day): string
    {
        $row = $this->query()->build($company->toRfc4122(), $account->toRfc4122(), $day, $day)->executeQuery()->fetchAssociative();
        self::assertIsArray($row);

        return SalesCohortCoverageQuery::mapRow($row)->ordersCoverage;
    }

    private function dayLoads(): MarketplaceDayLoadRepository
    {
        $repository = self::getContainer()->get(MarketplaceDayLoadRepository::class);
        \assert($repository instanceof MarketplaceDayLoadRepository);

        return $repository;
    }

    private function query(): SalesCohortCoverageQuery
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return new SalesCohortCoverageQuery($connection);
    }
}
