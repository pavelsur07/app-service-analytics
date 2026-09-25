<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Coverage;

use App\Ingestion\Application\Message\CheckOzonAdSkuReportMessage;
use App\Ingestion\Application\Message\FetchOzonAdCampaignStatsMessage;
use App\Ingestion\Application\Message\FetchOzonCatalogMessage;
use App\Ingestion\Application\Message\FetchOzonExpensesMessage;
use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use App\Ingestion\Application\Message\FetchOzonReturnsMessage;
use App\Ingestion\Application\Message\OrderOzonAdSkuReportMessage;
use App\Ingestion\Application\OzonAdvertisingWindows;
use App\Ingestion\Domain\Coverage\CoverageFailure;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\OzonAdReportKind;
use App\Ingestion\Infrastructure\Query\Coverage\FailedMessagesQuery;

/**
 * Какие дни каких эндпоинтов должны были загрузить сообщения кабинета,
 * лежащие в `failed`. Чужое сообщение — другая компания или другой
 * кабинет — отбрасывается точным сравнением (CLAUDE.md §1).
 */
final readonly class FailedLoads
{
    private const string TIMEZONE = 'Europe/Moscow';

    /** Потолок чтения очереди `failed` на отчёт: 40 пачек по 500. */
    private const int MAX_BATCHES = 40;

    public function __construct(
        private FailedMessagesQuery $failedMessages,
    ) {
    }

    /**
     * @return list<CoverageFailure>
     */
    public function forAccount(string $companyId, string $marketplaceAccountId): array
    {
        $failures = [];
        $before = null;
        for ($batch = 0; $batch < self::MAX_BATCHES; ++$batch) {
            $rows = $this->failedMessages->build($companyId, $marketplaceAccountId, $before)->executeQuery()->fetchAllAssociative();
            foreach ($rows as $row) {
                $before = FailedMessagesQuery::id($row);
                $failed = FailedMessagesQuery::decode($row);
                if (null === $failed) {
                    continue;
                }
                foreach (self::failuresOf($failed->message, $companyId, $marketplaceAccountId, $failed->failedOn) as $failure) {
                    $failures[] = new CoverageFailure($failure->reportType, $failure->from, $failure->to, $failed->failedAt);
                }
            }

            if (\count($rows) < FailedMessagesQuery::BATCH) {
                return $failures;
            }
        }

        // Громко, не тихая обрезка: десятки тысяч упавших загрузок одного
        // кабинета — авария, и отчёт без части ошибок показал бы их «нет
        // данных» вместо «ошибка».
        throw new \RuntimeException('Failed messages of the account exceed the safety ceiling of the coverage report.');
    }

    /**
     * @return list<CoverageFailure>
     */
    public static function failuresOf(object $message, string $companyId, string $marketplaceAccountId, \DateTimeImmutable $failedOn): array
    {
        $owner = property_exists($message, 'companyId') && property_exists($message, 'marketplaceAccountId')
            ? [$message->companyId, $message->marketplaceAccountId]
            : null;
        if ($owner !== [$companyId, $marketplaceAccountId]) {
            return [];
        }

        $range = static fn (string $type, ?string $from, ?string $to): array => null === ($f = self::day($from)) || null === ($t = self::day($to)) || $t < $f
            ? []
            : [new CoverageFailure($type, $f, $t)];

        return match (true) {
            $message instanceof FetchOzonPostingsMessage => $range(MarketplaceReportType::OzonPostingFboList, $message->businessDate, $message->businessDate),
            $message instanceof FetchOzonExpensesMessage => $range(MarketplaceReportType::OzonAccrualByDay, $message->accrualDate, $message->accrualDate),
            $message instanceof FetchOzonReturnsMessage => $range(MarketplaceReportType::OzonReturnsList, $message->from, $message->to),
            // Каталог — снимок без даты в сообщении: день снимка — день отказа.
            $message instanceof FetchOzonCatalogMessage => [
                new CoverageFailure(MarketplaceReportType::OzonProductList, $failedOn, $failedOn),
                new CoverageFailure(MarketplaceReportType::OzonProductInfoList, $failedOn, $failedOn),
            ],
            $message instanceof FetchOzonAdCampaignStatsMessage => [
                ...$range(MarketplaceReportType::OzonAdExpense, $message->from, $message->to),
                ...$range(MarketplaceReportType::OzonAdDaily, $message->from, $message->to),
                ...self::headChunkFailures($message->from, $message->to, $failedOn),
            ],
            $message instanceof OrderOzonAdSkuReportMessage => $range(OzonAdReportKind::rawType($message->reportKind()), $message->from, $message->to),
            $message instanceof CheckOzonAdSkuReportMessage => $range(OzonAdReportKind::rawType($message->reportKind()), $message->from, $message->periodTo()),
            default => [],
        };
    }

    /**
     * Головной кусок рекламы — тот, что кончается в последние 30 дней
     * на момент отказа (`OzonAdvertisingWindows::isHeadChunk`), — грузит
     * ещё список кампаний (день снимка — конец куска) и `products/sku`
     * за вчера и сегодня на момент выполнения внутри куска
     * (`OzonAdvertisingWindows::skuDays`); момент выполнения — день отказа.
     *
     * @return list<CoverageFailure>
     */
    private static function headChunkFailures(string $from, string $to, \DateTimeImmutable $failedOn): array
    {
        $start = self::day($from);
        $end = self::day($to);
        if (null === $start || null === $end || !OzonAdvertisingWindows::isHeadChunk($end, $failedOn)) {
            return [];
        }

        $failures = [new CoverageFailure(MarketplaceReportType::OzonAdCampaigns, $end, $end)];
        foreach (OzonAdvertisingWindows::skuDays($start, $end, $failedOn) as $day) {
            $failures[] = new CoverageFailure(MarketplaceReportType::OzonAdSkuDay, $day, $day);
        }

        return $failures;
    }

    private static function day(?string $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone(self::TIMEZONE));

        return false === $day || $day->format('Y-m-d') !== $value ? null : $day;
    }
}
