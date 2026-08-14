<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\MarkMarketplaceAccountBrokenAction;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountBrokenNotifier;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ADR-007: отказ авторизации переводит подключение в broken и порождает
 * письмо клиенту. Молчаливая остановка синхронизации запрещена, поэтому
 * проверяется не только состояние, но и факт уведомления.
 */
final class MarkMarketplaceAccountBrokenActionTest extends KernelTestCase
{
    public function testAuthorizationFailureBreaksTheAccountAndNotifiesTheClient(): void
    {
        $container = $this->bootedContainer();
        $account = $this->activeAccount($container);
        $notifier = $this->recordingNotifier();

        $changed = ($this->action($container, $notifier))($account->companyId()->toRfc4122(), $account->id()->toRfc4122());

        self::assertTrue($changed);
        self::assertSame('broken', $this->state($container, $account));
        self::assertSame([$account->id()->toRfc4122()], $notifier->notified);
    }

    public function testSecondFailureOfTheSameAccountDoesNotNotifyAgain(): void
    {
        $container = $this->bootedContainer();
        $account = $this->activeAccount($container);
        $notifier = $this->recordingNotifier();
        $action = $this->action($container, $notifier);

        // У подключения две задачи в очереди — продажи и каталог, — и обе
        // получат отказ авторизации. Письмо клиент должен получить одно:
        // условие «было active» живёт внутри UPDATE, поэтому второй вызов
        // уходит ни с чем (CLAUDE.md §4).
        $action($account->companyId()->toRfc4122(), $account->id()->toRfc4122());
        $repeated = $action($account->companyId()->toRfc4122(), $account->id()->toRfc4122());

        self::assertFalse($repeated);
        self::assertCount(1, $notifier->notified);
    }

    public function testAccountOfAnotherCompanyIsNotBroken(): void
    {
        $container = $this->bootedContainer();
        $ours = $this->activeAccount($container);
        $notifier = $this->recordingNotifier();

        // Обязательное покрытие ADR-005: изоляция между компаниями.
        // Идентификатор подключения знает и посторонний — он ездит
        // в сообщениях очереди, — и защитой служит только companyId
        // внутри самого UPDATE.
        $changed = ($this->action($container, $notifier))(
            CompanyBuilder::aCompany()->persistWith($this->companies($container))->id()->toRfc4122(),
            $ours->id()->toRfc4122(),
        );

        self::assertFalse($changed);
        self::assertSame('active', $this->state($container, $ours));
        self::assertSame([], $notifier->notified);
    }

    private function action(ContainerInterface $container, MarketplaceAccountBrokenNotifier $notifier): MarkMarketplaceAccountBrokenAction
    {
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = $container->get(MarketplaceAccountRepository::class);

        return new MarkMarketplaceAccountBrokenAction($accounts, $notifier);
    }

    private function activeAccount(ContainerInterface $container): MarketplaceAccount
    {
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = $container->get(MarketplaceAccountRepository::class);

        return MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($this->companies($container)))
            ->withState(MarketplaceAccountState::Active)
            ->persistWith($this->companies($container), $accounts);
    }

    private function companies(ContainerInterface $container): CompanyRepository
    {
        /** @var CompanyRepository $companies */
        $companies = $container->get(CompanyRepository::class);

        return $companies;
    }

    /**
     * Состояние читается сырым SQL, не через ORM: переход выполняется
     * условным UPDATE, и загруженная в память сущность о нём не знает.
     */
    private function state(ContainerInterface $container, MarketplaceAccount $account): string
    {
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        $state = $connection->fetchOne(
            'SELECT state FROM marketplace_account WHERE id = ?',
            [$account->id()->toRfc4122()],
        );
        self::assertIsString($state);

        return $state;
    }

    /**
     * @return MarketplaceAccountBrokenNotifier&object{notified: list<string>}
     */
    private function recordingNotifier(): MarketplaceAccountBrokenNotifier
    {
        return new class implements MarketplaceAccountBrokenNotifier {
            /** @var list<string> */
            public array $notified = [];

            public function accountBroken(string $companyId, MarketplaceAccount $account): void
            {
                $this->notified[] = $account->id()->toRfc4122();
            }
        };
    }

    private function bootedContainer(): ContainerInterface
    {
        self::bootKernel();

        return self::getContainer();
    }
}
