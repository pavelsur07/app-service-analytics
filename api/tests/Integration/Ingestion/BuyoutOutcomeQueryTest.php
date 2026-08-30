<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\BuyoutOutcomeQuery;
use App\Ingestion\Infrastructure\Query\BuyoutOutcomeRow;
use App\Ingestion\Infrastructure\Query\UnclassifiedOzonBuyoutReasonRow;
use App\Ingestion\Infrastructure\Query\UnclassifiedOzonBuyoutReasonsQuery;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BuyoutOutcomeQueryTest extends KernelTestCase
{
    private Uuid $companyId;
    private Uuid $accountId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
    }

    public function testClassifiesMixedOrdersHistoryReturnsUnknownsAndTenantIsolation(): void
    {
        $sales = [];
        $statuses = [];
        $returns = [];

        // Mixed order: отказ при вручении только одной SKU становится P,
        // доставленный sibling остаётся D и сохраняет quantity=2.
        $sales[] = $this->sale('MIX-A', 'MIX-1-A', 'MIX-1', 'cancelled');
        $sales[] = $this->sale('MIX-B', 'MIX-1-B', 'MIX-1', 'delivered', 2);
        $statuses = [...$statuses,
            $this->postingStatus('MIX-1-A', 'MIX-1', 'awaiting_packaging', '2026-08-01 09:00:00'),
            $this->postingStatus('MIX-1-A', 'MIX-1', 'cancelled', '2026-08-03 11:00:00'),
            $this->postingStatus('MIX-1-B', 'MIX-1', 'delivering', '2026-08-02 10:00:00'),
            $this->postingStatus('MIX-1-B', 'MIX-1', 'delivered', '2026-08-03 12:00:00'),
        ];
        $returns[] = $this->returnFact('RET-MIX-A', 'MIX-A', 'MIX-1-A', 'MIX-1', 'Cancellation', 'Покупатель отказался при вручении: товар не подошел');

        // Отказ без выкупленного sibling: при полностью разрешившемся
        // заказе это T2; второй sibling с явной отменой до handover — T1.
        $sales[] = $this->sale('NO-D-A', 'NO-D-1-A', 'NO-D-1', 'cancelled');
        $sales[] = $this->sale('NO-D-B', 'NO-D-1-B', 'NO-D-1', 'cancelled');
        foreach (['A', 'B'] as $suffix) {
            $statuses[] = $this->postingStatus('NO-D-1-'.$suffix, 'NO-D-1', 'awaiting_packaging', '2026-08-01 09:00:00');
            $statuses[] = $this->postingStatus('NO-D-1-'.$suffix, 'NO-D-1', 'cancelled', '2026-08-03 11:00:00');
        }
        $returns[] = $this->returnFact('RET-NO-D-A', 'NO-D-A', 'NO-D-1-A', 'NO-D-1', 'Cancellation', 'Покупатель отказался при вручении: товар не подошел');
        $returns[] = $this->returnFact('RET-NO-D-B', 'NO-D-B', 'NO-D-1-B', 'NO-D-1', 'Cancellation', 'Покупатель отменил заказ');

        // Пока sibling не разрешился, HANDOVER_REFUSAL остаётся unknown.
        $sales[] = $this->sale('WAIT-A', 'WAIT-1-A', 'WAIT-1', 'cancelled');
        $sales[] = $this->sale('WAIT-B', 'WAIT-1-B', 'WAIT-1', 'awaiting_packaging');
        $statuses[] = $this->postingStatus('WAIT-1-A', 'WAIT-1', 'awaiting_packaging', '2026-08-01 09:00:00');
        $statuses[] = $this->postingStatus('WAIT-1-A', 'WAIT-1', 'cancelled', '2026-08-03 11:00:00');
        $statuses[] = $this->postingStatus('WAIT-1-B', 'WAIT-1', 'awaiting_packaging', '2026-08-03 11:00:00');
        $returns[] = $this->returnFact('RET-WAIT-A', 'WAIT-A', 'WAIT-1-A', 'WAIT-1', 'Cancellation', 'Покупатель отказался при вручении: товар не подошел');

        // Delivered + ClientReturn — R независимо от локализованной причины.
        $sales[] = $this->sale('RETURNED', 'RETURNED-1', 'RETURNED', 'delivered');
        $statuses[] = $this->postingStatus('RETURNED-1', 'RETURNED', 'delivering', '2026-08-02 10:00:00');
        $statuses[] = $this->postingStatus('RETURNED-1', 'RETURNED', 'delivered', '2026-08-03 12:00:00');
        $returns[] = $this->returnFact('RET-CLIENT', 'RETURNED', 'RETURNED-1', 'RETURNED', 'ClientReturn', 'Любая локализованная причина');

        // Явная отмена до handover и отмена после доказанного handover.
        $sales[] = $this->sale('EARLY', 'EARLY-1', 'EARLY', 'cancelled');
        $statuses[] = $this->postingStatus('EARLY-1', 'EARLY', 'awaiting_packaging', '2026-08-01 09:00:00');
        $statuses[] = $this->postingStatus('EARLY-1', 'EARLY', 'cancelled', '2026-08-03 11:00:00');
        $returns[] = $this->returnFact('RET-EARLY', 'EARLY', 'EARLY-1', 'EARLY', 'Cancellation', 'Покупатель отменил заказ');

        $sales[] = $this->sale('LATE', 'LATE-1', 'LATE', 'cancelled');
        $statuses[] = $this->postingStatus('LATE-1', 'LATE', 'delivering', '2026-08-02 10:00:00');
        $statuses[] = $this->postingStatus('LATE-1', 'LATE', 'cancelled', '2026-08-03 11:00:00');

        // Одна terminal-строка без истории и неизвестная причина не
        // превращаются в правдоподобную, но неверную категорию.
        $sales[] = $this->sale('AMBIGUOUS', 'AMBIGUOUS-1', 'AMBIGUOUS', 'cancelled');
        $statuses[] = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withPostingNumber('AMBIGUOUS-1')
            ->withOrderNumber('AMBIGUOUS')
            ->withStatus('cancelled', null)
            ->withObservedAt(new \DateTimeImmutable('2026-08-03 11:00:00'))
            ->withRawDocumentId(Uuid::v7())
            ->build();
        $returns[] = $this->returnFact('RET-AMBIGUOUS', 'AMBIGUOUS', 'AMBIGUOUS-1', 'AMBIGUOUS', 'Cancellation', 'Покупатель отменил заказ');

        $sales[] = $this->sale('UNKNOWN', 'UNKNOWN-1', 'UNKNOWN', 'cancelled');
        $statuses[] = $this->postingStatus('UNKNOWN-1', 'UNKNOWN', 'awaiting_packaging', '2026-08-01 09:00:00');
        $statuses[] = $this->postingStatus('UNKNOWN-1', 'UNKNOWN', 'cancelled', '2026-08-03 11:00:00');
        $returns[] = $this->returnFact('RET-UNKNOWN', 'UNKNOWN', 'UNKNOWN-1', 'UNKNOWN', 'Cancellation', 'Неизвестная новая причина');

        $sales[] = $this->sale('PENDING', 'PENDING-1', 'PENDING', 'awaiting_packaging');
        $statuses[] = $this->postingStatus('PENDING-1', 'PENDING', 'awaiting_packaging', '2026-08-03 11:00:00');

        // Новая пара status/substatus не должна становиться T1 только потому,
        // что return reason уже известен.
        $sales[] = $this->sale('STATUS-DRIFT', 'STATUS-DRIFT-1', 'STATUS-DRIFT', 'cancelled');
        $statuses[] = $this->postingStatus('STATUS-DRIFT-1', 'STATUS-DRIFT', 'new_queue', '2026-08-01 09:00:00', 'new_substatus');
        $statuses[] = $this->postingStatus('STATUS-DRIFT-1', 'STATUS-DRIFT', 'cancelled', '2026-08-03 11:00:00');
        $returns[] = $this->returnFact('RET-STATUS-DRIFT', 'STATUS-DRIFT', 'STATUS-DRIFT-1', 'STATUS-DRIFT', 'Cancellation', 'Покупатель отменил заказ');

        $sales[] = $this->sale('SUBSTATUS-DRIFT', 'SUBSTATUS-DRIFT-1', 'SUBSTATUS-DRIFT', 'delivered');
        $statuses[] = $this->postingStatus('SUBSTATUS-DRIFT-1', 'SUBSTATUS-DRIFT', 'delivered', '2026-08-03 11:00:00', 'new_delivered_substatus');

        // Raw snapshots могут попасть в одну секунду; UUIDv7 остаётся
        // effective-time tie-breaker и доказывает handover до terminal.
        $sales[] = $this->sale('SAME-SECOND', 'SAME-SECOND-1', 'SAME-SECOND', 'cancelled');
        $statuses[] = $this->postingStatus('SAME-SECOND-1', 'SAME-SECOND', 'delivering', '2026-08-03 11:00:00');
        $statuses[] = $this->postingStatus('SAME-SECOND-1', 'SAME-SECOND', 'cancelled', '2026-08-03 11:00:00');

        // Совпавший order_number чужой компании не является sibling.
        $sales[] = $this->sale('CROSS', 'CROSS-1', 'SHARED-ORDER', 'cancelled');
        $statuses[] = $this->postingStatus('CROSS-1', 'SHARED-ORDER', 'cancelled', '2026-08-03 11:00:00');
        $returns[] = $this->returnFact('RET-CROSS', 'CROSS', 'CROSS-1', 'SHARED-ORDER', 'Cancellation', 'Покупатель отказался при вручении: товар не подошел');

        $foreignCompany = Uuid::v7();
        $foreignAccount = Uuid::v7();
        $foreignSale = SalesFactBuilder::aSalesFact()
            ->withCompanyId($foreignCompany)
            ->withMarketplaceAccountId($foreignAccount)
            ->withSourceRowId('FOREIGN-1|FOREIGN')
            ->withPostingNumber('FOREIGN-1')
            ->withOrderNumber('SHARED-ORDER')
            ->withMarketplaceSku('FOREIGN')
            ->withStatus('delivered')
            ->build();
        $foreignStatuses = [
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($foreignCompany)
                ->withMarketplaceAccountId($foreignAccount)
                ->withPostingNumber('FOREIGN-1')
                ->withOrderNumber('SHARED-ORDER')
                ->withStatus('delivered')
                ->build(),
        ];

        $this->sales()->upsertAll([...$sales, $foreignSale]);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), $statuses);
        $this->postingStatuses()->recordChanged($foreignCompany->toRfc4122(), $foreignStatuses);
        $this->returns()->upsertAll($returns);

        $rows = $this->rows();
        self::assertSame([
            'AMBIGUOUS' => null,
            'CROSS' => 'T2',
            'EARLY' => 'T1',
            'LATE' => 'T2',
            'MIX-A' => 'P',
            'MIX-B' => 'D',
            'NO-D-A' => 'T2',
            'NO-D-B' => 'T1',
            'PENDING' => null,
            'RETURNED' => 'R',
            'SAME-SECOND' => 'T2',
            'STATUS-DRIFT' => null,
            'SUBSTATUS-DRIFT' => null,
            'UNKNOWN' => null,
            'WAIT-A' => null,
            'WAIT-B' => null,
        ], array_map(static fn (BuyoutOutcomeRow $row): ?string => $row->outcome, $rows));

        self::assertSame(2, $rows['MIX-B']->quantity);
        self::assertSame('2026-08-02 10:00:00', $rows['MIX-B']->handedOverAt);
        self::assertSame('2026-08-03 12:00:00', $rows['MIX-B']->resolvedAt);
        self::assertNull($rows['EARLY']->handedOverAt);
        self::assertSame('2026-08-03 11:00:00', $rows['EARLY']->resolvedAt);
        self::assertNull($rows['PENDING']->resolvedAt);
        self::assertCount(16, $rows);
    }

    public function testUnclassifiedDiagnosticsGroupsUnknownTerminalReasonsButSkipsPendingAndForeignRows(): void
    {
        $sales = [];
        $statuses = [];
        $returns = [];
        foreach ([['DIAG-A', '2026-08-01'], ['DIAG-B', '2026-08-03']] as [$sku, $date]) {
            $posting = $sku.'-1';
            $sales[] = $this->sale($sku, $posting, $sku, 'cancelled', 1, new \DateTimeImmutable($date));
            $statuses[] = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($this->accountId)
                ->withPostingNumber($posting)
                ->withOrderNumber($sku)
                ->withStatus('cancelled', 'posting_canceled', 506)
                ->withObservedAt(new \DateTimeImmutable('2026-08-04 10:00:00'))
                ->build();
            $returns[] = $this->returnFact('RET-'.$sku, $sku, $posting, $sku, 'Cancellation', 'Новая неизвестная причина');
        }

        $sales[] = $this->sale('DIAG-PENDING', 'DIAG-PENDING-1', 'DIAG-PENDING', 'awaiting_packaging');
        $statuses[] = $this->postingStatus('DIAG-PENDING-1', 'DIAG-PENDING', 'awaiting_packaging', '2026-08-04 10:00:00');
        $this->sales()->upsertAll($sales);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), $statuses);
        $this->returns()->upsertAll($returns);

        $foreignCompany = Uuid::v7();
        $foreignAccount = Uuid::v7();
        $foreignSale = SalesFactBuilder::aSalesFact()
            ->withCompanyId($foreignCompany)
            ->withMarketplaceAccountId($foreignAccount)
            ->withSourceRowId('FOREIGN-DIAG|DIAG')
            ->withPostingNumber('FOREIGN-DIAG')
            ->withOrderNumber('FOREIGN-DIAG')
            ->withMarketplaceSku('DIAG')
            ->withStatus('cancelled')
            ->build();
        $foreignStatus = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($foreignCompany)
            ->withMarketplaceAccountId($foreignAccount)
            ->withPostingNumber('FOREIGN-DIAG')
            ->withOrderNumber('FOREIGN-DIAG')
            ->withStatus('cancelled', 'posting_canceled', 506)
            ->build();
        $foreignReturn = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($foreignCompany)
            ->withMarketplaceAccountId($foreignAccount)
            ->withSourceRowId('FOREIGN-RETURN')
            ->withOrderNumber('FOREIGN-DIAG')
            ->withMarketplaceSku('DIAG')
            ->withPostingNumber('FOREIGN-DIAG')
            ->withReturnReasonName('Новая неизвестная причина')
            ->build();
        $this->sales()->upsertAll([$foreignSale]);
        $this->postingStatuses()->recordChanged($foreignCompany->toRfc4122(), [$foreignStatus]);
        $this->returns()->upsertAll([$foreignReturn]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $query = new UnclassifiedOzonBuyoutReasonsQuery($connection);
        $rawRows = $query->build($this->companyId->toRfc4122(), $this->accountId->toRfc4122(), 50)
            ->executeQuery()
            ->fetchAllAssociative();
        $rows = array_map(UnclassifiedOzonBuyoutReasonsQuery::mapRow(...), $rawRows);

        self::assertCount(1, $rows);
        self::assertInstanceOf(UnclassifiedOzonBuyoutReasonRow::class, $rows[0]);
        self::assertSame('Cancellation', $rows[0]->returnType);
        self::assertSame('Новая неизвестная причина', $rows[0]->returnReasonName);
        self::assertSame('cancelled', $rows[0]->status);
        self::assertSame('posting_canceled', $rows[0]->substatus);
        self::assertSame(506, $rows[0]->cancelReasonId);
        self::assertSame(2, $rows[0]->affectedRows);
        self::assertSame('2026-08-01', $rows[0]->firstBusinessDate);
        self::assertSame('2026-08-03', $rows[0]->lastBusinessDate);
    }

    public function testSplitsPartialReturnQuantitiesInsteadOfColoringTheWholeSaleLine(): void
    {
        $sales = [
            $this->sale('PARTIAL-R', 'PARTIAL-R-1', 'PARTIAL-R', 'delivered', 2),
            $this->sale('PARTIAL-P', 'PARTIAL-P-1', 'PARTIAL-P', 'cancelled', 2),
            $this->sale('PARTIAL-P-SIBLING', 'PARTIAL-P-2', 'PARTIAL-P', 'delivered'),
        ];
        $statuses = [
            $this->postingStatus('PARTIAL-R-1', 'PARTIAL-R', 'delivering', '2026-08-02 10:00:00'),
            $this->postingStatus('PARTIAL-R-1', 'PARTIAL-R', 'delivered', '2026-08-03 10:00:00'),
            $this->postingStatus('PARTIAL-P-1', 'PARTIAL-P', 'cancelled', '2026-08-03 10:00:00'),
            $this->postingStatus('PARTIAL-P-2', 'PARTIAL-P', 'delivered', '2026-08-03 10:00:00'),
        ];
        $returns = [
            $this->returnFact('RET-PARTIAL-R', 'PARTIAL-R', 'PARTIAL-R-1', 'PARTIAL-R', 'ClientReturn', 'Возврат одной единицы'),
            $this->returnFact('RET-PARTIAL-P', 'PARTIAL-P', 'PARTIAL-P-1', 'PARTIAL-P', 'Cancellation', 'Покупатель отказался при вручении: товар не подошел'),
        ];
        $this->sales()->upsertAll($sales);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), $statuses);
        $this->returns()->upsertAll($returns);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame([
            ['outcome' => 'D', 'quantity' => 1],
            ['outcome' => 'R', 'quantity' => 1],
        ], $connection->fetchAllAssociative(
            'SELECT outcome, quantity FROM buyout_outcome WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? ORDER BY outcome',
            [$this->companyId->toRfc4122(), $this->accountId->toRfc4122(), 'PARTIAL-R-1|PARTIAL-R'],
        ));
        self::assertSame([
            ['outcome' => 'P', 'quantity' => 1],
            ['outcome' => null, 'quantity' => 1],
        ], $connection->fetchAllAssociative(
            'SELECT outcome, quantity FROM buyout_outcome WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? ORDER BY outcome NULLS LAST',
            [$this->companyId->toRfc4122(), $this->accountId->toRfc4122(), 'PARTIAL-P-1|PARTIAL-P'],
        ));
    }

    public function testDiagnosticsIncludesUnknownStatusPairsAndMissingHistory(): void
    {
        $sales = [];
        $statuses = [];
        foreach (['awaiting_packaging', 'awaiting_deliver', 'delivering', 'delivered', 'cancelled'] as $status) {
            $sku = 'DRIFT-'.strtoupper($status);
            $posting = $sku.'-1';
            $sales[] = $this->sale($sku, $posting, $sku, $status);
            $statuses[] = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
                ->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($this->accountId)
                ->withPostingNumber($posting)
                ->withOrderNumber($sku)
                ->withStatus($status, 'new_unknown_substatus')
                ->withObservedAt(new \DateTimeImmutable('2026-08-04 10:00:00'))
                ->build();
        }
        $sales[] = $this->sale('NO-HISTORY', 'NO-HISTORY-1', 'NO-HISTORY', 'delivered');
        $this->sales()->upsertAll($sales);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), $statuses);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $rawRows = (new UnclassifiedOzonBuyoutReasonsQuery($connection))
            ->build($this->companyId->toRfc4122(), $this->accountId->toRfc4122(), 50)
            ->executeQuery()
            ->fetchAllAssociative();
        $rows = array_map(UnclassifiedOzonBuyoutReasonsQuery::mapRow(...), $rawRows);
        $pairs = array_map(
            static fn (UnclassifiedOzonBuyoutReasonRow $row): string => ($row->status ?? 'NULL').'/'.($row->substatus ?? 'NULL'),
            $rows,
        );
        sort($pairs);

        self::assertSame([
            'NULL/NULL',
            'awaiting_deliver/new_unknown_substatus',
            'awaiting_packaging/new_unknown_substatus',
            'cancelled/new_unknown_substatus',
            'delivered/new_unknown_substatus',
            'delivering/new_unknown_substatus',
        ], $pairs);
    }

    private function sale(
        string $sku,
        string $posting,
        string $order,
        string $status,
        int $quantity = 1,
        ?\DateTimeImmutable $businessDate = null,
    ): \App\Ingestion\Domain\SalesFact {
        $builder = SalesFactBuilder::aSalesFact()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSourceRowId($posting.'|'.$sku)
            ->withPostingNumber($posting)
            ->withOrderNumber($order)
            ->withMarketplaceSku($sku)
            ->withStatus($status)
            ->withQuantity($quantity);

        return (null === $businessDate ? $builder : $builder->withBusinessDate($businessDate))->build();
    }

    private function postingStatus(
        string $posting,
        string $order,
        string $status,
        string $observedAt,
        ?string $substatus = null,
    ): \App\Ingestion\Domain\MarketplacePostingStatus {
        $substatus ??= match ($status) {
            'awaiting_packaging' => 'posting_created',
            'awaiting_deliver' => 'posting_transferring_to_delivery',
            'delivering' => 'posting_on_way_to_city',
            'delivered' => 'posting_received',
            'cancelled' => 'posting_canceled',
            default => null,
        };

        return MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withPostingNumber($posting)
            ->withOrderNumber($order)
            ->withStatus($status, $substatus)
            ->withObservedAt(new \DateTimeImmutable($observedAt))
            ->withRawDocumentId(Uuid::v7())
            ->build();
    }

    private function returnFact(
        string $id,
        string $sku,
        string $posting,
        string $order,
        string $type,
        string $reason,
    ): \App\Ingestion\Domain\MarketplaceReturnFact {
        return MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSourceRowId($id)
            ->withOrderNumber($order)
            ->withMarketplaceSku($sku)
            ->withPostingNumber($posting)
            ->withReturnType($type)
            ->withReturnReasonName($reason)
            ->build();
    }

    /**
     * @return array<string, BuyoutOutcomeRow>
     */
    private function rows(): array
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $query = new BuyoutOutcomeQuery($connection);
        $rawRows = $query->build($this->companyId->toRfc4122(), $this->accountId->toRfc4122(), 200)
            ->executeQuery()
            ->fetchAllAssociative();
        $rows = [];
        foreach ($rawRows as $rawRow) {
            $row = BuyoutOutcomeQuery::mapRow($rawRow);
            $rows[$row->marketplaceSku] = $row;
        }
        ksort($rows);

        return $rows;
    }

    private function sales(): SalesFactRepository
    {
        /** @var SalesFactRepository $repository */
        $repository = self::getContainer()->get(SalesFactRepository::class);

        return $repository;
    }

    private function postingStatuses(): MarketplacePostingStatusRepository
    {
        /** @var MarketplacePostingStatusRepository $repository */
        $repository = self::getContainer()->get(MarketplacePostingStatusRepository::class);

        return $repository;
    }

    private function returns(): MarketplaceReturnFactRepository
    {
        /** @var MarketplaceReturnFactRepository $repository */
        $repository = self::getContainer()->get(MarketplaceReturnFactRepository::class);

        return $repository;
    }
}
