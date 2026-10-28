<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\DayLoadMark;
use App\Ingestion\Domain\FactCaptureOrigin;
use App\Ingestion\Domain\MarketplaceDayLoadRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use Symfony\Component\Uid\Uuid;

/**
 * Отметка загрузки дня (ADR-038). Сущность пишется только upsert-ом
 * репозитория, поэтому Builder её не собирает, а записывает тем же путём,
 * что и обработчики: успех или отказ последнего прогона.
 */
final class MarketplaceDayLoadBuilder
{
    private Uuid $companyId;
    private Uuid $marketplaceAccountId;
    private string $reportType = MarketplaceReportType::OzonAccrualByDay;
    private \DateTimeImmutable $period;
    private ?\DateTimeImmutable $endPeriod = null;
    private ?string $failure = null;
    private ?\DateTimeImmutable $at = null;

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->marketplaceAccountId = Uuid::v7();
        $this->period = new \DateTimeImmutable('2026-08-10');
    }

    public static function aMarketplaceDayLoad(): self
    {
        return new self();
    }

    public function withCompanyId(Uuid $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withMarketplaceAccountId(Uuid $marketplaceAccountId): self
    {
        $clone = clone $this;
        $clone->marketplaceAccountId = $marketplaceAccountId;

        return $clone;
    }

    public function withReportType(string $reportType): self
    {
        $clone = clone $this;
        $clone->reportType = $reportType;

        return $clone;
    }

    public function withPeriod(\DateTimeImmutable $period): self
    {
        $clone = clone $this;
        $clone->period = $period;
        $clone->endPeriod = null;

        return $clone;
    }

    public function withRange(\DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        $clone = clone $this;
        $clone->period = $from;
        $clone->endPeriod = $to;

        return $clone;
    }

    /** Последний прогон дня отказал. */
    public function failed(string $failure = 'разбор не понял'): self
    {
        $clone = clone $this;
        $clone->failure = $failure;

        return $clone;
    }

    /** Последний прогон дня записал все страницы. */
    public function completed(): self
    {
        $clone = clone $this;
        $clone->failure = null;

        return $clone;
    }

    /** Момент прогона; по умолчанию — сейчас. */
    public function withAt(\DateTimeImmutable $at): self
    {
        $clone = clone $this;
        $clone->at = $at;

        return $clone;
    }

    public function persistWith(MarketplaceDayLoadRepository $repository, FactCaptureOrigin $origin = FactCaptureOrigin::Unknown): void
    {
        $companyId = $this->companyId->toRfc4122();
        $accountId = $this->marketplaceAccountId->toRfc4122();
        $at = $this->at ?? new \DateTimeImmutable();

        if (null === $this->failure) {
            $repository->mark($companyId, $accountId, [DayLoadMark::completed($this->reportType, $this->period, $this->endPeriod ?? $this->period)], $at, $origin);

            return;
        }

        $repository->mark($companyId, $accountId, [DayLoadMark::failed($this->reportType, $this->period, $this->endPeriod ?? $this->period, $this->failure)], $at, $origin);
    }
}
