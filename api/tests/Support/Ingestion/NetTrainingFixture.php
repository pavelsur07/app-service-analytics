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
use App\Tests\Support\Builder\MarketplaceDayLoadBuilder;
use App\Tests\Support\Builder\MarketplaceDayLoadReceiptBuilder;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** Only fixture inputs: real normalization/UPSERT/capture, no synthetic ledger DML. */
final class NetTrainingFixture
{
    public readonly Uuid $company;
    public readonly Uuid $account;
    public readonly \DateTimeImmutable $initial;
    public readonly \DateTimeImmutable $terminal;
    public readonly \DateTimeImmutable $readAt;
    public readonly NetForecastCaptureEpoch $epoch;
    /** @var array<string, Uuid> */
    private array $postingRawIds = [];

    public function __construct(private readonly Connection $db, ?Uuid $company = null, ?Uuid $account = null)
    {
        $this->company = $company ?? Uuid::v7();
        $this->account = $account ?? Uuid::v7();
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $this->initial = $today->modify('+1 day');
        $this->terminal = $this->initial->modify('+2 days');
        $this->readAt = $this->initial->modify('+80 days');
        $this->epoch = new NetForecastCaptureEpoch(Uuid::v7()->toRfc4122(), 'accepted_state_v1', $today, null, 'synthetic-capture-v1');
    }

    public function posting(string $key, string $sku, int $quantity, string $terminal = 'delivered', ?string $order = null, ?string $posting = null, ?\DateTimeImmutable $terminalAt = null, bool $missingOrder = false, bool $handedOver = false, string $cancellationReason = 'Покупатель отменил заказ', bool $terminalOnly = false, ?\DateTimeImmutable $businessDay = null, FactCaptureOrigin $initialOrigin = FactCaptureOrigin::LivePolling): void
    {
        $order ??= $key;
        $posting ??= $key;
        $terminalAt ??= $this->terminal;
        $businessDate = $businessDay ?? new \DateTimeImmutable($this->initial->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d'));
        $base = SalesFactBuilder::aSalesFact()->withCompanyId($this->company)->withMarketplaceAccountId($this->account)
            ->withSourceRowId($key)->withMarketplaceSku($sku)->withPostingNumber($posting)->withOrderNumber($missingOrder ? null : $order)
            ->withBusinessDate($businessDate)->withOrderedAt($this->initial)->withQuantity($quantity);
        $states = $terminalOnly ? [] : [[$this->initial, 'awaiting_packaging']];
        if ($handedOver) {
            $states[] = [$terminalAt->modify('-1 day'), 'delivering'];
        }
        $states[] = [$terminalAt, $terminal];
        foreach ($states as $index => [$at, $status]) {
            $origin = 0 === $index && !$terminalOnly ? $initialOrigin : FactCaptureOrigin::LivePolling;
            // All SKU in a posting/poll share its actual page document identity.
            $poll = $posting.'|'.$at->format('c');
            $raw = $this->postingRawIds[$poll] ??= Uuid::v7();
            (new DoctrineSalesFactWriter($this->db, new MockClock($at)))->upsertAll([$base->withRawDocumentId($raw)->withStatus($status)->build()], $origin);
            (new DoctrineMarketplacePostingStatusWriter($this->db))->recordChanged($this->company->toRfc4122(), [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($this->company)->withMarketplaceAccountId($this->account)->withPostingNumber($posting)->withOrderNumber($order)
                ->withRawDocumentId($raw)->withStatus($status)->withObservedAt($at)->build()]);
            (new DoctrineMarketplaceDayLoadWriter($this->db))->mark($this->company->toRfc4122(), $this->account->toRfc4122(), [DayLoadMark::completed(MarketplaceReportType::OzonPostingFboList, $businessDate, $businessDate)], $at->modify('+1 second'), $origin);
        }
        if ('cancelled' === $terminal) {
            $this->returned($order, $sku, $quantity, $terminalAt->modify('+1 hour'), 'Cancellation', $cancellationReason);
        }
    }

    public function returned(string $key, string $sku, int $quantity, \DateTimeImmutable $at, string $type = 'ClientReturn', string $reason = 'synthetic client return', ?string $sourceKey = null, ?\DateTimeImmutable $visualAt = null): void
    {
        $fact = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()->withCompanyId($this->company)->withMarketplaceAccountId($this->account)
            ->withSourceRowId($sourceKey ?? 'return-'.$key.'-'.$sku)->withOrderNumber($key)->withPostingNumber('return-'.$key)->withMarketplaceSku($sku)
            ->withReturnType($type)->withReturnReasonName($reason)->withQuantity($quantity)
            ->withRawDocumentId(Uuid::v7())->withVisualStatus(1, 'Completed', $visualAt ?? $at)->build();
        (new DoctrineMarketplaceReturnFactWriter($this->db, new MockClock($at)))->upsertAll([$fact], FactCaptureOrigin::LivePolling);
    }

    /** @param list<int> $missingDays */
    public function returnCoverage(array $missingDays = [], ?\DateTimeImmutable $deepAt = null): void
    {
        $first = $this->terminal->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0);
        for ($day = 0; $day <= 67; ++$day) {
            if (\in_array($day, $missingDays, true)) {
                continue;
            }
            $date = $first->modify('+'.$day.' days');
            $this->receipt(MarketplaceReportType::OzonReturnsList, $date, $date, $date->modify('+1 day +1 hour'));
        }
        $this->returnDeepReceipt($deepAt ?? $this->terminal->modify('+68 days'));
    }

    public function returnDeepReceipt(\DateTimeImmutable $at): void
    {
        $first = $this->terminal->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0);
        $this->receipt(MarketplaceReportType::OzonReturnsList, $first, $first->modify('+60 days'), $at);
    }

    public function returnDayReceipt(int $offset, \DateTimeImmutable $at, bool $completed = true, FactCaptureOrigin $origin = FactCaptureOrigin::LivePolling): void
    {
        $day = $this->terminal->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0)->modify('+'.$offset.' days');
        $this->receipt(MarketplaceReportType::OzonReturnsList, $day, $day, $at, $completed, $origin);
    }

    public function postingsDayReceipt(\DateTimeImmutable $at): void
    {
        $day = new \DateTimeImmutable($this->initial->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d'));
        $this->receipt(MarketplaceReportType::OzonPostingFboList, $day, $day, $at);
    }

    public function priceCorrection(string $key, string $sku, int $quantity, \DateTimeImmutable $at, ?string $order = null): int
    {
        $raw = Uuid::v7();
        $day = new \DateTimeImmutable($this->initial->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d'));
        $fact = SalesFactBuilder::aSalesFact()->withCompanyId($this->company)->withMarketplaceAccountId($this->account)
            ->withSourceRowId($key)->withMarketplaceSku($sku)->withPostingNumber($key)->withOrderNumber($order ?? $key)
            ->withBusinessDate($day)->withOrderedAt($this->initial)->withQuantity($quantity)->withRawDocumentId($raw)
            ->withAmount(Money::ofMinor(12345, 'RUB'))->withCommissionAmount(Money::ofMinor(123, 'RUB'))->build();
        (new DoctrineSalesFactWriter($this->db, new MockClock($at)))->upsertAll([$fact], FactCaptureOrigin::LivePolling);
        $inserted = (new DoctrineMarketplacePostingStatusWriter($this->db))->recordChanged($this->company->toRfc4122(), [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($this->company)->withMarketplaceAccountId($this->account)->withPostingNumber($key)->withOrderNumber($order ?? $key)
            ->withRawDocumentId($raw)->withStatus('delivered')->withObservedAt($at)->build()]);
        $this->receipt(MarketplaceReportType::OzonPostingFboList, $day, $day, $at->modify('+1 second'));

        return $inserted;
    }

    public function deliveryMetadata(string $key, string $sku, int $quantity, \DateTimeImmutable $at, string $substatus, bool $anchored): void
    {
        $raw = Uuid::v7();
        if ($anchored) {
            $this->priceCorrection($key, $sku, $quantity, $at);
            $id = $this->db->fetchOne('SELECT raw_document_id FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?', [$this->company->toRfc4122(), $this->account->toRfc4122(), $key]);
            if (!\is_string($id)) {
                throw new \RuntimeException('Missing real accepted metadata anchor.');
            }
            $raw = Uuid::fromString($id);
        }
        (new DoctrineMarketplacePostingStatusWriter($this->db))->recordChanged($this->company->toRfc4122(), [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($this->company)->withMarketplaceAccountId($this->account)->withPostingNumber($key)->withOrderNumber($key)
            ->withRawDocumentId($raw)->withStatus('delivered', $substatus)->withObservedAt($at)->build()]);
    }

    private function receipt(string $reportType, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $at, bool $completed = true, FactCaptureOrigin $origin = FactCaptureOrigin::LivePolling): void
    {
        $load = MarketplaceDayLoadBuilder::aMarketplaceDayLoad()->withCompanyId($this->company)->withMarketplaceAccountId($this->account)
            ->withReportType($reportType)->withRange($from, $to)->withAt($at);
        MarketplaceDayLoadReceiptBuilder::aMarketplaceDayLoadReceipt()->withLoad($completed ? $load : $load->failed('synthetic failure'))->withOrigin($origin)
            ->persistWith(new DoctrineMarketplaceDayLoadWriter($this->db));
    }

    public function reopenWithDeduplicatedInitialRaw(string $key, string $sku, int $quantity): void
    {
        $raw = $this->db->fetchOne('SELECT raw_document_id FROM sales_fact_revision WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND fact_revision = 1', [$this->company->toRfc4122(), $this->account->toRfc4122(), $key]);
        if (!\is_string($raw)) {
            throw new \RuntimeException('Missing initial fixture state.');
        }
        $day = new \DateTimeImmutable($this->initial->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d'));
        $at = $this->terminal->modify('+1 day');
        $fact = SalesFactBuilder::aSalesFact()->withCompanyId($this->company)->withMarketplaceAccountId($this->account)
            ->withSourceRowId($key)->withMarketplaceSku($sku)->withPostingNumber($key)->withOrderNumber($key)
            ->withBusinessDate($day)->withOrderedAt($this->initial)->withQuantity($quantity)->withRawDocumentId(Uuid::fromString($raw))->withStatus('awaiting_packaging')->build();
        (new DoctrineSalesFactWriter($this->db, new MockClock($at)))->upsertAll([$fact], FactCaptureOrigin::LivePolling);
        (new DoctrineMarketplacePostingStatusWriter($this->db))->recordChanged($this->company->toRfc4122(), [MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($this->company)->withMarketplaceAccountId($this->account)->withPostingNumber($key)->withOrderNumber($key)
            ->withRawDocumentId(Uuid::fromString($raw))->withStatus('awaiting_packaging')->withObservedAt($at)->build()]);
        (new DoctrineMarketplaceDayLoadWriter($this->db))->mark($this->company->toRfc4122(), $this->account->toRfc4122(), [DayLoadMark::completed(MarketplaceReportType::OzonPostingFboList, $day, $day)], $at->modify('+1 second'), FactCaptureOrigin::LivePolling);
    }
}
