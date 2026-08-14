<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Ingestion\Application\Message\FetchOzonCatalogMessage;
use App\Ingestion\Application\Message\FetchOzonExpensesMessage;
use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
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
        // Пять задач на подключение: продажи за сегодня, каталог целиком
        // и расходы за каждый день окна. Окно у расходов есть, а у продаж
        // нет, потому что начисление приходит позже продажи — иногда
        // на недели, и день, загруженный один раз, назавтра уже неполон.
        self::assertCount(10, $sent);

        $postingTargets = [];
        $catalogTargets = [];
        $expenseDates = [];
        foreach ($sent as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof FetchOzonPostingsMessage) {
                $postingTargets[] = $message->marketplaceAccountId;
            } elseif ($message instanceof FetchOzonCatalogMessage) {
                $catalogTargets[] = $message->marketplaceAccountId;
            } elseif ($message instanceof FetchOzonExpensesMessage) {
                $expenseDates[$message->marketplaceAccountId][] = $message->accrualDate;
            } else {
                self::fail('Планировщик поставил задачу неизвестного типа.');
            }
        }

        $today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow'));
        foreach ([$accountA, $accountB] as $account) {
            $id = $account->id()->toRfc4122();
            self::assertContains($id, $postingTargets);
            self::assertContains($id, $catalogTargets);
            self::assertSame(
                [
                    $today->format('Y-m-d'),
                    $today->modify('-1 day')->format('Y-m-d'),
                    $today->modify('-2 day')->format('Y-m-d'),
                ],
                $expenseDates[$id] ?? [],
            );
        }

        $today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow'));
        $firstMessage = $sent[0]->getMessage();
        self::assertInstanceOf(FetchOzonPostingsMessage::class, $firstMessage);
        self::assertSame($today->format('Y-m-d'), $firstMessage->businessDate);
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
        // Одно подключение — пять задач: продажи, каталог и расходы
        // за три дня окна.
        self::assertCount(5, $this->transport($container)->getSent());
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
