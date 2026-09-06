<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\MarkMarketplaceAccountBrokenAction;
use App\Identity\Domain\CompanyMemberRepository;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountBrokenNotifier;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\UserRepository;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Identity\Infrastructure\Notification\MailMarketplaceAccountBrokenNotifier;
use App\Identity\Infrastructure\Query\CompanyMemberEmailsQuery;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

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

    /**
     * Дефект 3 (CLAUDE.md-задача): состояние уже совершено внутри UPDATE
     * до вызова уведомителя, и отказ отправки — тот же класс хрупкости,
     * что закрыт вчера в OzonAccountBrokenLogger. С настоящим
     * MailMarketplaceAccountBrokenNotifier (не тестовой заглушкой выше)
     * отказ SMTP не должен ни отменить переход в broken, ни выйти
     * из действия — иначе сообщение очереди уйдёт в ретраи, а на повторе
     * markBrokenIfActive() вернёт false («уже не active»), и вызывающий
     * обработчик (например, FetchOzonPostingsHandler::findOzonSyncTarget())
     * не найдёт подключение там, где ищет только активные.
     */
    public function testRealNotifierSendFailureDoesNotUndoTheBrokenTransitionOrEscapeTheAction(): void
    {
        $container = $this->bootedContainer();
        $account = $this->activeAccountWithMember($container, 'owner@example.test');
        $failingMailer = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new \RuntimeException('SMTP недоступен');
            }
        };
        $handler = new TestHandler();
        $notifier = new MailMarketplaceAccountBrokenNotifier(
            new CompanyMemberEmailsQuery($this->dbal($container)),
            $failingMailer,
            new Logger('test', [$handler]),
        );
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = $container->get(MarketplaceAccountRepository::class);
        $action = new MarkMarketplaceAccountBrokenAction($accounts, $notifier);

        // Если отказ отправки уйдёт наружу, этот вызов сам провалит тест.
        $changed = $action($account->companyId()->toRfc4122(), $account->id()->toRfc4122());

        self::assertTrue($changed);
        self::assertSame('broken', $this->state($container, $account));
        self::assertTrue($handler->hasWarningThatContains('Не удалось отправить письмо о сломанном подключении'));
    }

    private function activeAccountWithMember(ContainerInterface $container, string $email): MarketplaceAccount
    {
        /** @var CompanyRepository $companies */
        $companies = $this->companies($container);
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = $container->get(MarketplaceAccountRepository::class);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        /** @var CompanyMemberRepository $members */
        $members = $container->get(CompanyMemberRepository::class);

        $company = CompanyBuilder::aCompany()->persistWith($companies);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($company)
            ->withUser(UserBuilder::aUser()->withEmail($email)->persistWith($users))
            ->persistWith($companies, $users, $members);

        return MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->withState(MarketplaceAccountState::Active)
            ->persistWith($companies, $accounts);
    }

    private function dbal(ContainerInterface $container): Connection
    {
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        return $connection;
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
