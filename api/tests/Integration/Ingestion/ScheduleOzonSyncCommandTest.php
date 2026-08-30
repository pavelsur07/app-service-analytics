<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Ingestion\Application\Message\FetchOzonCatalogMessage;
use App\Ingestion\Application\Message\FetchOzonExpensesMessage;
use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use App\Ingestion\Application\Message\FetchOzonReturnsMessage;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ScheduleOzonSyncCommandTest extends KernelTestCase
{
    public function testOnceDispatchesOneMessagePerActiveAccountAcrossCompanies(): void
    {
        $container = $this->bootedContainer();
        $companies = $this->companies($container);
        $marketplaceAccounts = $this->marketplaceAccounts($container);

        $accountA = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-a')
            ->persistWith($companies, $marketplaceAccounts);
        $accountB = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-b')
            ->persistWith($companies, $marketplaceAccounts);
        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-broken')
            ->withState(MarketplaceAccountState::Broken)
            ->persistWith($companies, $marketplaceAccounts);

        $tester = $this->commandTester();
        $tester->execute(['--once' => true]);
        $tester->assertCommandIsSuccessful();

        $sent = $this->transport($container)->getSent();

        $postingDates = [];
        $catalogTargets = [];
        $expenseDates = [];
        $returnRanges = [];
        foreach ($sent as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof FetchOzonPostingsMessage) {
                $postingDates[$message->marketplaceAccountId][] = $message->businessDate;
            } elseif ($message instanceof FetchOzonCatalogMessage) {
                $catalogTargets[$message->marketplaceAccountId][] = $message->marketplaceAccountId;
            } elseif ($message instanceof FetchOzonExpensesMessage) {
                $expenseDates[$message->marketplaceAccountId][] = $message->accrualDate;
            } elseif ($message instanceof FetchOzonReturnsMessage) {
                $returnRanges[$message->marketplaceAccountId][] = [$message->from, $message->to];
            } else {
                self::fail('Планировщик поставил задачу неизвестного типа.');
            }
        }

        $today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow'));
        $window = [
            $today->format('Y-m-d'),
            $today->modify('-1 day')->format('Y-m-d'),
            $today->modify('-2 day')->format('Y-m-d'),
        ];

        foreach ([$accountA, $accountB] as $account) {
            $id = $account->id()->toRfc4122();
            self::assertSame([$id], array_values(array_unique($catalogTargets[$id] ?? [])));
            self::assertSame($window, $expenseDates[$id] ?? []);
            $returnDays = 3 === (int) $today->format('G') ? 90 : 3;
            self::assertSame([[
                $today->modify('-'.($returnDays - 1).' day')->format('Y-m-d'),
                $today->format('Y-m-d'),
            ]], $returnRanges[$id] ?? []);

            // Окно продаж — не «сегодня»: заказ меняет статус после
            // загрузки, и день, спрошенный один раз, застывает
            // (DispatchActiveOzonSyncsAction). Точное число дней здесь
            // не проверяется намеренно: в час глубокого рескана оно
            // другое, и жёсткое равенство сделало бы тест зависящим
            // от времени суток.
            self::assertSame($window, \array_slice($postingDates[$id] ?? [], 0, 3));
        }
    }

    public function testOnceSkipsTickWhenLockAlreadyHeld(): void
    {
        $container = $this->bootedContainer();
        $companies = $this->companies($container);
        $marketplaceAccounts = $this->marketplaceAccounts($container);
        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->persistWith($companies, $marketplaceAccounts);

        /** @var LockFactory $lockFactory */
        $lockFactory = $container->get(LockFactory::class);
        $externalLock = $lockFactory->createLock('ingestion.schedule-ozon-sync');
        self::assertTrue($externalLock->acquire());

        try {
            $tester = $this->commandTester();
            $tester->execute(['--once' => true]);
            $tester->assertCommandIsSuccessful();

            self::assertCount(0, $this->transport($container)->getSent());
        } finally {
            $externalLock->release();
        }

        $tester = $this->commandTester();
        $tester->execute(['--once' => true]);
        // Предмет теста — что замок отпущен и тик состоялся, а не сколько
        // именно задач он поставил: число дней в окне продаж зависит
        // от часа (глубокий рескан), и жёсткое равенство сделало бы
        // тест зависящим от времени суток.
        self::assertNotEmpty($this->transport($container)->getSent());
    }

    private function bootedContainer(): ContainerInterface
    {
        self::bootKernel();

        return self::getContainer();
    }

    private function commandTester(): CommandTester
    {
        /** @var \Symfony\Component\HttpKernel\KernelInterface $kernel */
        $kernel = self::$kernel;
        $application = new Application($kernel);
        $command = $application->find('app:ingestion:schedule-ozon-sync');

        return new CommandTester($command);
    }

    private function transport(ContainerInterface $container): InMemoryTransport
    {
        $transport = $container->get('messenger.transport.async_ingestion');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function companies(ContainerInterface $container): CompanyRepository
    {
        /** @var CompanyRepository $companies */
        $companies = $container->get(CompanyRepository::class);

        return $companies;
    }

    private function marketplaceAccounts(ContainerInterface $container): MarketplaceAccountRepository
    {
        /** @var MarketplaceAccountRepository $marketplaceAccounts */
        $marketplaceAccounts = $container->get(MarketplaceAccountRepository::class);

        return $marketplaceAccounts;
    }
}
