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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
        $handler = new TestHandler();
        $action = $this->action($container, $notifier, new Logger('test', [$handler]));

        // У подключения две задачи в очереди — продажи и каталог, — и обе
        // получат отказ авторизации. Письмо клиент должен получить одно:
        // условие «было active» живёт внутри UPDATE, поэтому второй вызов
        // уходит ни с чем (CLAUDE.md §4).
        $action($account->companyId()->toRfc4122(), $account->id()->toRfc4122());
        $handler->clear();
        $repeated = $action($account->companyId()->toRfc4122(), $account->id()->toRfc4122());

        self::assertFalse($repeated);
        self::assertCount(1, $notifier->notified);
        // Путь «состояние не менялось» выходит из действия до всякого
        // обращения к логгеру дефекта 3 — записи здесь быть не должно.
        self::assertSame([], $handler->getRecords());
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
        $action = new MarkMarketplaceAccountBrokenAction($accounts, $notifier, new NullLogger());

        // Если отказ отправки уйдёт наружу, этот вызов сам провалит тест.
        $changed = $action($account->companyId()->toRfc4122(), $account->id()->toRfc4122());

        self::assertTrue($changed);
        self::assertSame('broken', $this->state($container, $account));
        self::assertTrue($handler->hasWarningThatContains('Не удалось отправить письмо о сломанном подключении'));
    }

    /**
     * Дефект 3, третье и последнее место той же формы: между переводом
     * в broken (уже закоммичен) и чтением для уведомления строка исчезает —
     * сегодня это не только теоретическая гонка, а реальный путь через
     * DiscardUnusedConnectionAction (удаление подключения клиентом).
     * Исключение отсюда не должно уйти наружу (иначе — тот же сценарий
     * ретраев и ложного «not found», что и у отказа письма выше),
     * состояние обязано остаться broken, и пропажа обязана остаться
     * видимой записью warning.
     */
    public function testAccountDisappearingBeforeNotificationDoesNotEscapeAndLeavesAWarning(): void
    {
        $container = $this->bootedContainer();
        $account = $this->activeAccount($container);
        $notifier = $this->recordingNotifier();
        $handler = new TestHandler();
        /** @var MarketplaceAccountRepository $realAccounts */
        $realAccounts = $container->get(MarketplaceAccountRepository::class);
        $accounts = new class($realAccounts) implements MarketplaceAccountRepository {
            public function __construct(private readonly MarketplaceAccountRepository $inner)
            {
            }

            public function add(MarketplaceAccount $account): void
            {
                $this->inner->add($account);
            }

            public function get(string $companyId, \Symfony\Component\Uid\Uuid $id): ?MarketplaceAccount
            {
                // Имитирует удаление строки другим запросом между переводом
                // в broken и этим чтением (DiscardUnusedConnectionAction).
                return null;
            }

            public function markBrokenIfActive(string $companyId, \Symfony\Component\Uid\Uuid $id): bool
            {
                return $this->inner->markBrokenIfActive($companyId, $id);
            }

            public function tryConnect(MarketplaceAccount $account, \App\Identity\Domain\AuditRecord $trail): bool
            {
                return $this->inner->tryConnect($account, $trail);
            }

            public function deleteIfNoHistory(string $companyId, \Symfony\Component\Uid\Uuid $id, \Closure $isEligibleForDeletion, \Symfony\Component\Uid\Uuid $actorUserId): \App\Identity\Domain\DiscardAccountOutcome
            {
                return $this->inner->deleteIfNoHistory($companyId, $id, $isEligibleForDeletion, $actorUserId);
            }
        };
        $action = new MarkMarketplaceAccountBrokenAction($accounts, $notifier, new Logger('test', [$handler]));

        // Если исчезновение строки уйдёт исключением наружу, этот вызов
        // сам провалит тест.
        $changed = $action($account->companyId()->toRfc4122(), $account->id()->toRfc4122());

        self::assertTrue($changed);
        self::assertSame('broken', $this->state($container, $account));
        self::assertSame([], $notifier->notified);
        self::assertTrue($handler->hasWarningThatContains('исчезло до отправки уведомления'));
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

    private function action(ContainerInterface $container, MarketplaceAccountBrokenNotifier $notifier, ?LoggerInterface $logger = null): MarkMarketplaceAccountBrokenAction
    {
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = $container->get(MarketplaceAccountRepository::class);

        return new MarkMarketplaceAccountBrokenAction($accounts, $notifier, $logger ?? new NullLogger());
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
