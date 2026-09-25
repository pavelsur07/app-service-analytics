<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Application\Facade\IdentityScheduleFacade;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Ingestion\Application\DispatchActiveOzonSyncsAction;
use App\Ingestion\Application\Message\FetchOzonAdCampaignsMessage;
use App\Ingestion\Application\Message\FetchOzonAdCampaignStatsMessage;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Расписание рекламы (ADR-026 п. 4): только подключениям с активной
 * рекламой, окно 45 дней на обычном тике, 184 дня — в недельный рескан.
 * Признак рекламы приходит из межарендаторного запроса планировщика,
 * поэтому проверяется через него, а не заглушкой.
 *
 * День и час рескана задаются конструктором явно — иначе тест проходил бы
 * шесть дней в неделю и падал в седьмой.
 */
final class AdvertisingScheduleTest extends KernelTestCase
{
    private const string TIMEZONE = 'Europe/Moscow';

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
    }

    public function testOnlyAccountsWithActiveAdvertisingGetAdvertisingLoads(): void
    {
        $withAds = $this->account(advertising: true);
        $withoutAds = $this->account(advertising: false);

        $this->action(rescanHour: $this->hourThatIsNotNow(), weekday: $this->weekdayNow())();

        // Список кампаний сохраняет сам свежий кусок перед products/sku;
        // второй запрос того же метода на тике упирался в лимит (429).
        self::assertSame(0, $this->campaignLoads($withAds));
        self::assertSame([
            [$this->daysAgo(29), $this->daysAgo(0)],
            [$this->daysAgo(44), $this->daysAgo(30)],
        ], $this->statChunks($withAds));

        self::assertSame(0, $this->campaignLoads($withoutAds));
        self::assertSame([], $this->statChunks($withoutAds));
    }

    public function testWeeklyDeepRescanReplacesTheTickWindow(): void
    {
        $account = $this->account(advertising: true);

        $this->action(rescanHour: $this->hourNow(), weekday: $this->weekdayNow())();

        $chunks = $this->statChunks($account);
        self::assertCount(7, $chunks);
        self::assertSame($this->daysAgo(0), $chunks[0][1]);
        self::assertSame($this->daysAgo(183), $chunks[6][0]);
    }

    public function testDailyRescanHourOnAnotherWeekdayKeepsTheTickWindow(): void
    {
        $account = $this->account(advertising: true);

        $this->action(rescanHour: $this->hourNow(), weekday: $this->weekdayNow() % 7 + 1)();

        self::assertCount(2, $this->statChunks($account));
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

    private function campaignLoads(MarketplaceAccount $account): int
    {
        $count = 0;
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof FetchOzonAdCampaignsMessage && $message->marketplaceAccountId === $account->id()->toRfc4122()) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @return list<array{string, string}>
     */
    private function statChunks(MarketplaceAccount $account): array
    {
        $chunks = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof FetchOzonAdCampaignStatsMessage && $message->marketplaceAccountId === $account->id()->toRfc4122()) {
                $chunks[] = [$message->from, $message->to];
            }
        }

        return $chunks;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_ingestion');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
    }

    private function daysAgo(int $days): string
    {
        return $this->now()->modify("-{$days} day")->format('Y-m-d');
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
