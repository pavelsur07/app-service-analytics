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
        foreach ($this->failedMessages->forAccount($marketplaceAccountId) as $failed) {
            foreach (self::failuresOf($failed->message, $companyId, $marketplaceAccountId, $failed->failedOn) as $failure) {
                $failures[] = $failure;
            }
        }

        return $failures;
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
            ],
            $message instanceof OrderOzonAdSkuReportMessage => $range(OzonAdReportKind::rawType($message->reportKind()), $message->from, $message->to),
            // У проверки отчёта только начало периода; кусок — не длиннее 30 дней.
            $message instanceof CheckOzonAdSkuReportMessage => $range(
                OzonAdReportKind::rawType($message->reportKind()),
                $message->from,
                self::day($message->from)?->modify('+29 days')->format('Y-m-d'),
            ),
            default => [],
        };
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
