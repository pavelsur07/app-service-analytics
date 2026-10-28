<?php

declare(strict_types=1);

namespace App\Tests\Support\Ingestion;

use App\Ingestion\Domain\DayLoadMark;
use App\Ingestion\Domain\FactCaptureOrigin;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\ValueObject\NetForecastCaptureEpoch;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceDayLoadWriter;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplacePostingStatusWriter;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceReturnFactWriter;
use App\Ingestion\Infrastructure\Persistence\DoctrineSalesFactWriter;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** Real accepted observations and receipts; corruption helpers are explicitly separate. */
final class NetTargetFixture
{
    public readonly Uuid $company;
    public readonly Uuid $account;
    public readonly \DateTimeImmutable $readAt;
    public readonly NetForecastCaptureEpoch $epoch;
    /** @var array<string, Uuid> */
    private array $rawIds = [];

    public function __construct(private readonly Connection $db, ?\DateTimeImmutable $readAt = null, ?Uuid $company = null, ?Uuid $account = null)
    {
        $this->company = $company ?? Uuid::v7();
        $this->account = $account ?? Uuid::v7();
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $this->readAt = $readAt ?? $today->modify('+200 days +12 hours');
        $this->epoch = new NetForecastCaptureEpoch(Uuid::v7()->toRfc4122(), 'accepted_state_v1', $today, null, 'synthetic-target-capture');
    }

    public function sale(string $source, string $sku, int $quantity, string $status, \DateTimeImmutable $at, ?string $order = null, ?string $posting = null, ?\DateTimeImmutable $day = null, FactCaptureOrigin $origin = FactCaptureOrigin::LivePolling, bool $missingOrder = false, ?string $substatus = null, ?\DateTimeImmutable $observedAt = null, ?Money $amount = null): void
    {
        $order ??= $source;
        $posting ??= $source;
        $day ??= new \DateTimeImmutable($this->readAt->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d'));
        $raw = $this->rawIds[$posting.'|'.$at->format('U.u').'|'.$status] ??= Uuid::v7();
        $fact = SalesFactBuilder::aSalesFact()->withCompanyId($this->company)->withMarketplaceAccountId($this->account)
            ->withSourceRowId($source)->withMarketplaceSku($sku)->withQuantity($quantity)->withStatus($status)
            ->withOrderNumber($missingOrder ? null : $order)->withPostingNumber($posting)->withBusinessDate($day)
            ->withOrderedAt($at)->withRawDocumentId($raw)->withAmount($amount ?? Money::ofMinor(12345, 'RUB'))->build();
        (new DoctrineSalesFactWriter($this->db, new MockClock($at)))->upsertAll([$fact], $origin);
        $statusBuilder = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($this->company)->withMarketplaceAccountId($this->account)
            ->withPostingNumber($posting)->withOrderNumber($order)->withRawDocumentId($raw)->withObservedAt($observedAt ?? $at);
        $statusBuilder = null === $substatus ? $statusBuilder->withStatus($status) : $statusBuilder->withStatus($status, $substatus);
        (new DoctrineMarketplacePostingStatusWriter($this->db))->recordChanged($this->company->toRfc4122(), [$statusBuilder->build()]);
        $this->receipt(MarketplaceReportType::OzonPostingFboList, $day, $day, $at->modify('+1 second'), origin: $origin);
    }

    public function delivered(string $source, string $sku, int $quantity, \DateTimeImmutable $origin, ?string $order = null, ?\DateTimeImmutable $day = null, ?Money $amount = null): void
    {
        $this->sale($source, $sku, $quantity, 'awaiting_packaging', $origin->modify('-2 hours'), $order, day: $day, amount: $amount);
        $this->sale($source, $sku, $quantity, 'delivered', $origin, $order, day: $day, amount: $amount);
    }

    public function returned(string $order, string $sku, int $quantity, \DateTimeImmutable $at, string $source = 'RETURN', string $type = 'ClientReturn', string $reason = 'synthetic client return'): void
    {
        $fact = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()->withCompanyId($this->company)->withMarketplaceAccountId($this->account)
            ->withSourceRowId($source)->withOrderNumber($order)->withPostingNumber('trace-'.$order)->withMarketplaceSku($sku)
            ->withQuantity($quantity)->withReturnType($type)->withReturnReasonName($reason)->withRawDocumentId(Uuid::v7())->withVisualStatus(1, 'Completed', $at)->build();
        (new DoctrineMarketplaceReturnFactWriter($this->db, new MockClock($at)))->upsertAll([$fact], FactCaptureOrigin::LivePolling);
    }

    public function currentReturns(?\DateTimeImmutable $at = null, bool $completed = true): void
    {
        $day = $this->readAt->setTimezone(new \DateTimeZone('Europe/Moscow'));
        $this->receipt(MarketplaceReportType::OzonReturnsList, $day, $day, $at ?? $this->readAt->modify('-1 second'), $completed);
    }

    /** @param list<int> $missing */
    public function daily(\DateTimeImmutable $origin, array $missing = [], bool $deep = false): void
    {
        $from = $origin->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0);
        $through = min($this->readAt->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0)->modify('-1 day'), $origin->modify('+67 days')->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0));
        for ($day = $from, $i = 0; $day <= $through; $day = $day->modify('+1 day'), ++$i) {
            if (!\in_array($i, $missing, true)) {
                $this->receipt(MarketplaceReportType::OzonReturnsList, $day, $day, $day->modify('+1 day +1 hour')->setTimezone(new \DateTimeZone('UTC')));
            }
        }
        if ($deep) {
            $this->receipt(MarketplaceReportType::OzonReturnsList, $from, $origin->modify('+60 days')->setTimezone(new \DateTimeZone('Europe/Moscow')), $origin->modify('+68 days'));
        }
    }

    public function receipt(string $report, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $at, bool $completed = true, FactCaptureOrigin $origin = FactCaptureOrigin::LivePolling): void
    {
        $mark = $completed ? DayLoadMark::completed($report, $from, $to) : DayLoadMark::failed($report, $from, $to, 'synthetic failure');
        (new DoctrineMarketplaceDayLoadWriter($this->db))->mark($this->company->toRfc4122(), $this->account->toRfc4122(), [$mark], $at, $origin);
    }

    public function cleanup(): void
    {
        foreach (['sales_fact_revision', 'marketplace_return_fact_revision', 'marketplace_day_load_receipt', 'marketplace_posting_status', 'marketplace_day_load', 'marketplace_return_fact', 'sales_fact'] as $table) {
            $this->db->executeStatement('DELETE FROM '.$table.' WHERE company_id = ? AND marketplace_account_id = ?', [$this->company->toRfc4122(), $this->account->toRfc4122()]);
        }
    }
}
