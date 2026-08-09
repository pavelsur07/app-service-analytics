<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
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
        self::assertCount(2, $sent);

        $dispatchedAccountIds = array_map(
            static function ($envelope) {
                $message = $envelope->getMessage();
                self::assertInstanceOf(FetchOzonPostingsMessage::class, $message);

                return $message->marketplaceAccountId;
            },
            $sent,
        );
        self::assertContains($accountA->id()->toRfc4122(), $dispatchedAccountIds);
        self::assertContains($accountB->id()->toRfc4122(), $dispatchedAccountIds);

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
        self::assertCount(1, $this->transport($container)->getSent());
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
