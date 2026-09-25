<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain\Coverage;

use App\Ingestion\Domain\Coverage\CoverageDocument;
use App\Ingestion\Domain\Coverage\CoverageFailure;
use App\Ingestion\Domain\Coverage\DataCoverageCalculator;
use App\Ingestion\Domain\Coverage\DataCoverageSource;
use App\Ingestion\Domain\Coverage\DataCoverageStatus;
use App\Ingestion\Domain\MarketplaceReportType;
use PHPUnit\Framework\TestCase;

/**
 * Правила отчёта о полноте данных: как выгрузка ложится на дни месяца
 * и какой статус получает день.
 */
final class DataCoverageCalculatorTest extends TestCase
{
    public function testDayBasedSourceIsLoadedOnlyOnItsDays(): void
    {
        $coverage = $this->calculate(
            [MarketplaceReportType::OzonPostingFboList],
            [$this->document(MarketplaceReportType::OzonPostingFboList, '2026-09-01', '2026-09-01 10:00')],
            [],
            today: '2026-09-03',
        );

        self::assertSame(['loaded', 'missing', 'missing', 'pending'], $this->statuses($coverage->rows[0]->statuses, 4));
        self::assertSame(1, $coverage->rows[0]->covered);
        self::assertSame(3, $coverage->rows[0]->due);
    }

    public function testRangeCoversFromItsStartToTheDayOfReceipt(): void
    {
        // Расход рекламы кусками по 30 дней: кусок с 1-го, полученный 4-го,
        // покрывает 1–4, но не дни после получения.
        $coverage = $this->calculate(
            [MarketplaceReportType::OzonAdExpense],
            [$this->document(MarketplaceReportType::OzonAdExpense, '2026-09-01', '2026-09-04 09:00')],
            [],
            today: '2026-09-06',
        );

        self::assertSame(['loaded', 'loaded', 'loaded', 'loaded', 'missing', 'missing', 'pending'], $this->statuses($coverage->rows[0]->statuses, 7));
    }

    public function testRangeStartedBeforeTheMonthCoversItsFirstDays(): void
    {
        // Кусок с 20 августа (30 дней) заходит в сентябрь по 18-е включительно.
        $coverage = $this->calculate(
            [MarketplaceReportType::OzonAdDaily],
            [$this->document(MarketplaceReportType::OzonAdDaily, '2026-08-20', '2026-09-25 03:00')],
            [],
            today: '2026-09-25',
        );

        $statuses = $this->statuses($coverage->rows[0]->statuses, 19);
        self::assertSame('loaded', $statuses[17]);
        self::assertSame('missing', $statuses[18]);
    }

    public function testSkuReportLeavesYesterdayAndTodayToProductsSku(): void
    {
        $coverage = $this->calculate(
            [MarketplaceReportType::OzonAdSkuReport],
            [$this->document(MarketplaceReportType::OzonAdSkuReport, '2026-09-01', '2026-09-05 03:00')],
            [],
            today: '2026-09-05',
        );

        self::assertSame(['loaded', 'loaded', 'loaded', 'missing', 'missing'], $this->statuses($coverage->rows[0]->statuses, 5));
    }

    public function testFailureMarksOnlyDaysWithoutData(): void
    {
        // Загружено сильнее ошибки: старое сообщение в failed по дню,
        // который потом загрузился, — не ошибка (сентябрь 2026).
        $coverage = $this->calculate(
            [MarketplaceReportType::OzonAccrualByDay],
            [$this->document(MarketplaceReportType::OzonAccrualByDay, '2026-09-01', '2026-09-02 01:00')],
            [new CoverageFailure(MarketplaceReportType::OzonAccrualByDay, $this->day('2026-09-01'), $this->day('2026-09-02'))],
            today: '2026-09-03',
        );

        self::assertSame(['loaded', 'failed', 'missing'], $this->statuses($coverage->rows[0]->statuses, 3));
    }

    public function testTotalShowsTheWorstStatusOfTheDay(): void
    {
        $coverage = $this->calculate(
            [MarketplaceReportType::OzonPostingFboList, MarketplaceReportType::OzonAccrualByDay, MarketplaceReportType::OzonProductList],
            [
                $this->document(MarketplaceReportType::OzonPostingFboList, '2026-09-01', '2026-09-01 10:00'),
                $this->document(MarketplaceReportType::OzonAccrualByDay, '2026-09-01', '2026-09-01 10:00'),
                $this->document(MarketplaceReportType::OzonProductList, '2026-09-01', '2026-09-01 10:00'),
                $this->document(MarketplaceReportType::OzonPostingFboList, '2026-09-02', '2026-09-02 10:00'),
                $this->document(MarketplaceReportType::OzonProductList, '2026-09-02', '2026-09-02 10:00'),
            ],
            [new CoverageFailure(MarketplaceReportType::OzonAccrualByDay, $this->day('2026-09-03'), $this->day('2026-09-03'))],
            today: '2026-09-03',
        );

        self::assertSame(['loaded', 'missing', 'failed', 'pending'], $this->statuses($coverage->total->statuses, 4));
        self::assertSame(1, $coverage->total->covered);
        self::assertSame(3, $coverage->total->due);
        self::assertCount(30, $coverage->days);
    }

    /**
     * @param list<string>           $types
     * @param list<CoverageDocument> $documents
     * @param list<CoverageFailure>  $failures
     */
    private function calculate(array $types, array $documents, array $failures, string $today): \App\Ingestion\Domain\Coverage\DataCoverage
    {
        $sources = array_values(array_filter(
            DataCoverageSource::all(),
            static fn (DataCoverageSource $source): bool => \in_array($source->reportType, $types, true),
        ));

        return (new DataCoverageCalculator())->calculate($sources, $documents, $failures, $this->day('2026-09-01'), $this->day($today));
    }

    private function document(string $type, string $period, string $receivedMoscow): CoverageDocument
    {
        return new CoverageDocument($type, $this->day($period), new \DateTimeImmutable($receivedMoscow, new \DateTimeZone('Europe/Moscow')));
    }

    private function day(string $value): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Moscow'));
        self::assertInstanceOf(\DateTimeImmutable::class, $day);

        return $day;
    }

    /**
     * @param list<DataCoverageStatus> $statuses
     *
     * @return list<string>
     */
    private function statuses(array $statuses, int $first): array
    {
        return array_map(static fn (DataCoverageStatus $status): string => $status->value, \array_slice($statuses, 0, $first));
    }
}
