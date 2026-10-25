<?php

declare(strict_types=1);

namespace App\Ingestion\Application\ServiceCoverage;

use App\Ingestion\Infrastructure\Persistence\DbalServiceCoverageCaptureStore;
use App\Ingestion\Infrastructure\ServiceCoverage\OzonFetcherCoverageClient;
use App\Ingestion\Infrastructure\ServiceCoverage\ServiceCoverageState;

/**
 * Снять сводку покрытия ozon-fetcher и сохранить её для экрана
 * «Покрытие GO» (ADR-043 п. 6). Окно — 93 дня по Москве, заканчивая
 * сегодняшним: предел контракта и самое длинное окно экрана; короткое
 * экран вырезает из него сам.
 *
 * Вызывается процессом по расписанию, не из запроса пользователя
 * (services/AGENTS.md §2, решение владельца 25.10.2026).
 */
final readonly class CaptureServiceCoverageAction
{
    public const string SERVICE = 'ozon-fetcher';

    /** Предел окна контракта service-coverage v1. */
    public const int WINDOW_DAYS = 93;

    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private OzonFetcherCoverageClient $client,
        private DbalServiceCoverageCaptureStore $store,
    ) {
    }

    public function __invoke(\DateTimeImmutable $now): ServiceCoverageState
    {
        $today = $now->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0);
        $snapshot = $this->client->fetch(
            $today->modify(\sprintf('-%d days', self::WINDOW_DAYS - 1))->format('Y-m-d'),
            $today->format('Y-m-d'),
        );
        $this->store->record(self::SERVICE, $snapshot, $now);

        return $snapshot->state;
    }
}
