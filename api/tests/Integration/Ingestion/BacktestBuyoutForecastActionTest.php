<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\Buyout\BacktestBuyoutForecastAction;
use App\Ingestion\Application\Buyout\BuyoutBacktestPair;
use App\Ingestion\Domain\MarketplacePostingStatus;
use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BacktestBuyoutForecastActionTest extends KernelTestCase
{
    private Uuid $companyId;
    private Uuid $accountId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
    }

    public function testComparesForecastMadeOnPastDateWithFinalFact(): void
    {
        // Продажи и возвраты грузятся «сейчас», поэтому история строится
        // вперёд от сегодняшнего дня, а итог смотрится через 20 дней.
        $earliest = $this->loadUnrelatedReturn();
        $cohort = $earliest->modify('+1 day');
        $asOfDate = $cohort->modify('+1 day');
        $training = $cohort->modify('-10 days');

        // Обучение: 24 выкупа и 6 невыкупов после передачи, закрыты в день
        // заказа — p95 на дату прогноза 0, выкуп после передачи 80%.
        for ($index = 1; $index <= 30; ++$index) {
            $this->posting('TRAIN-'.$index, 'TRAIN', $training, $index <= 24 ? 'delivered' : 'cancelled', $training->modify('+11 hours'));
        }
        // Проверяемый день: 6 из 10 выкуплены, но закрылись через день
        // после даты прогноза — на неё все 10 ещё в пути.
        for ($index = 1; $index <= 10; ++$index) {
            $this->posting('COHORT-'.$index, 'SKU-A', $cohort, $index <= 6 ? 'delivered' : 'cancelled', $cohort->modify('+2 days +10 hours'));
        }

        $report = ($this->action())(
            $this->companyId->toRfc4122(),
            $asOfDate,
            $asOfDate,
            $cohort->modify('+20 days +12 hours'),
        );

        self::assertEquals([
            new BuyoutBacktestPair($asOfDate->format('Y-m-d'), $cohort->format('Y-m-d'), 1, 8000, null, 6000),
        ], $report->pairs);
        self::assertSame(2000, $report->buckets[0]->forecastMaeBps);
        self::assertSame(2000, $report->buckets[0]->forecastBiasBps);
        self::assertSame(0, $report->buckets[0]->naiveCount);
    }

    public function testRefusesDatesBeforeReturnsWereFirstLoaded(): void
    {
        $earliest = $this->loadUnrelatedReturn();

        $this->expectException(\InvalidArgumentException::class);
        ($this->action())(
            $this->companyId->toRfc4122(),
            $earliest->modify('-1 day'),
            $earliest,
            $earliest->modify('+30 days'),
        );
    }

    public function testRefusesCompanyWithoutLoadedReturns(): void
    {
        $this->expectException(\DomainException::class);
        ($this->action())($this->companyId->toRfc4122(), null, null, new \DateTimeImmutable('+30 days'));
    }

    /** Возврат без продажи: только задаёт нижнюю границу дат прогноза. */
    private function loadUnrelatedReturn(): \DateTimeImmutable
    {
        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returns->upsertAll([
            MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
                ->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($this->accountId)
                ->withSourceRowId('RET-UNRELATED')
                ->withPostingNumber('UNRELATED')
                ->withOrderNumber('ORDER-UNRELATED')
                ->withMarketplaceSku('SKU-UNRELATED')
                ->withReturnType('ClientReturn')
                ->withReturnReasonName('Возврат покупателя')
                ->build(),
        ]);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');

        return new \DateTimeImmutable($today.' 00:00:00', new \DateTimeZone('UTC'))->modify('+1 day');
    }

    private function posting(string $posting, string $sku, \DateTimeImmutable $day, string $terminal, \DateTimeImmutable $resolvedAt): void
    {
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([
            SalesFactBuilder::aSalesFact()
                ->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($this->accountId)
                ->withSourceRowId($posting.'|'.$sku)
                ->withPostingNumber($posting)
                ->withOrderNumber('ORDER-'.$posting)
                ->withMarketplaceSku($sku)
                ->withStatus($terminal)
                ->withBusinessDate($day)
                ->build(),
        ]);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($this->companyId->toRfc4122(), [
            $this->postingStatus($posting, 'delivering', $day->modify('+10 hours')),
            $this->postingStatus($posting, $terminal, $resolvedAt),
        ]);
    }

    private function postingStatus(string $posting, string $status, \DateTimeImmutable $observedAt): MarketplacePostingStatus
    {
        return MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withPostingNumber($posting)
            ->withOrderNumber('ORDER-'.$posting)
            ->withStatus($status)
            ->withObservedAt($observedAt)
            ->withRawDocumentId(Uuid::v7())
            ->build();
    }

    private function action(): BacktestBuyoutForecastAction
    {
        /** @var BacktestBuyoutForecastAction $action */
        $action = self::getContainer()->get(BacktestBuyoutForecastAction::class);

        return $action;
    }
}
