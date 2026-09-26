<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Application\Facade\IdentityScheduleFacade;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Ingestion\Application\DispatchActiveOzonSyncsAction;
use App\Ingestion\Application\Message\FetchOzonStocksMessage;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Расписание снимка остатков (ADR-034): раз в сутки, в час рескана.
 * Час рескана задаётся конструктором явно — иначе тест проходил бы
 * двадцать три часа в сутки и падал в двадцать четвёртый.
 */
final class StockScheduleTest extends KernelTestCase
{
    private const string TIMEZONE = 'Europe/Moscow';

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
    }

    public function testStockSnapshotIsQueuedOnlyOnTheRescanTick(): void
    {
        $account = $this->account(advertising: false);

        $this->action(rescanHour: $this->hourThatIsNotNow(), weekday: $this->weekdayNow())();
        self::assertSame(0, $this->stockMessages($account));

        // Раз в сутки (ADR-034): рекомендации строятся по суточному спросу.
        $this->action(rescanHour: $this->hourNow(), weekday: $this->weekdayNow())();
        self::assertSame(1, $this->stockMessages($account));
    }

    private function action(int $rescanHour, int $weekday): DispatchActiveOzonSyncsAction
    {
        $schedule = self::getContainer()->get(IdentityScheduleFacade::class);
        self::assertInstanceOf(IdentityScheduleFacade::class, $schedule);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return new DispatchActiveOzonSyncsAction(
            identitySchedule: $schedule,
            bus: $bus,
            rescanHour: $rescanHour,
            adDeepRescanWeekday: $weekday,
        );
    }

    private function account(bool $advertising): MarketplaceAccount
    {
        $companies = self::getContainer()->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);
        $accounts = self::getContainer()->get(MarketplaceAccountRepository::class);
        self::assertInstanceOf(MarketplaceAccountRepository::class, $accounts);

        $builder = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-'.bin2hex(random_bytes(4)));
        if ($advertising) {
            $builder = $builder->withAdvertisingConnected();
        }

        return $builder->persistWith($companies, $accounts);
    }

    private function stockMessages(MarketplaceAccount $account): int
    {
        $count = 0;
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof FetchOzonStocksMessage && $message->marketplaceAccountId === $account->id()->toRfc4122()) {
                ++$count;
            }
        }

        return $count;
    }

    private function transport(string $name = 'async_ingestion'): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
    }

    private function hourNow(): int
    {
        return (int) $this->now()->format('G');
    }

    private function hourThatIsNotNow(): int
    {
        return ($this->hourNow() + 12) % 24;
    }

    private function weekdayNow(): int
    {
        return (int) $this->now()->format('N');
    }
}
