<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Coverage;

use App\Ingestion\Application\Coverage\FailedLoads;
use App\Ingestion\Application\Message\CheckOzonAdSkuReportMessage;
use App\Ingestion\Application\Message\FetchOzonCatalogMessage;
use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use App\Ingestion\Application\Message\FetchOzonReturnsMessage;
use App\Ingestion\Domain\Coverage\CoverageFailure;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\OzonAdReportKind;
use PHPUnit\Framework\TestCase;

/**
 * Упавшее сообщение → дни, которые оно должно было загрузить; чужое —
 * другой компании или другого кабинета — не попадает в отчёт (CLAUDE.md §1).
 */
final class FailedLoadsTest extends TestCase
{
    private const string COMPANY = '019fe6ea-cd6a-7c81-a869-883a0a562b47';

    private const string ACCOUNT = '019fe6ea-cd99-7af8-bf4a-623a5a31cf7b';

    public function testMessagesMapToTheDaysTheyShouldHaveLoaded(): void
    {
        $failedOn = new \DateTimeImmutable('2026-09-10', new \DateTimeZone('Europe/Moscow'));

        self::assertSame(
            [[MarketplaceReportType::OzonPostingFboList, '2026-09-05', '2026-09-05']],
            $this->ranges(FailedLoads::failuresOf(new FetchOzonPostingsMessage(self::COMPANY, self::ACCOUNT, '2026-09-05'), self::COMPANY, self::ACCOUNT, $failedOn)),
        );
        self::assertSame(
            [[MarketplaceReportType::OzonReturnsList, '2026-09-01', '2026-09-03']],
            $this->ranges(FailedLoads::failuresOf(new FetchOzonReturnsMessage(self::COMPANY, self::ACCOUNT, '2026-09-01', '2026-09-03'), self::COMPANY, self::ACCOUNT, $failedOn)),
        );
        // Каталог — снимок: не загрузился в день отказа.
        self::assertSame(
            [[MarketplaceReportType::OzonProductList, '2026-09-10', '2026-09-10'], [MarketplaceReportType::OzonProductInfoList, '2026-09-10', '2026-09-10']],
            $this->ranges(FailedLoads::failuresOf(new FetchOzonCatalogMessage(self::COMPANY, self::ACCOUNT), self::COMPANY, self::ACCOUNT, $failedOn)),
        );
        self::assertSame(
            [[MarketplaceReportType::OzonAdCpoOrders, '2026-08-01', '2026-08-30']],
            $this->ranges(FailedLoads::failuresOf(new CheckOzonAdSkuReportMessage(self::COMPANY, self::ACCOUNT, '2026-08-01', '054cd190-6514-4465-8792-e3e11f396886', 3, OzonAdReportKind::CpoOrders), self::COMPANY, self::ACCOUNT, $failedOn)),
        );
    }

    public function testMessagesOfAnotherCompanyOrAccountAreIgnored(): void
    {
        $failedOn = new \DateTimeImmutable('2026-09-10');

        self::assertSame([], FailedLoads::failuresOf(new FetchOzonPostingsMessage('019fe6ea-0000-7000-8000-000000000001', self::ACCOUNT, '2026-09-05'), self::COMPANY, self::ACCOUNT, $failedOn));
        self::assertSame([], FailedLoads::failuresOf(new FetchOzonPostingsMessage(self::COMPANY, '019fe6ea-0000-7000-8000-000000000002', '2026-09-05'), self::COMPANY, self::ACCOUNT, $failedOn));
        self::assertSame([], FailedLoads::failuresOf(new \stdClass(), self::COMPANY, self::ACCOUNT, $failedOn));
    }

    /**
     * @param list<CoverageFailure> $failures
     *
     * @return list<array{string, string, string}>
     */
    private function ranges(array $failures): array
    {
        return array_map(
            static fn (CoverageFailure $failure): array => [$failure->reportType, $failure->from->format('Y-m-d'), $failure->to->format('Y-m-d')],
            $failures,
        );
    }
}
