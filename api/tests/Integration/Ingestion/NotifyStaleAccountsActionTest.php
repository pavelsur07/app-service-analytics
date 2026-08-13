<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Application\Facade\IdentityScheduleFacade;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Infrastructure\Query\ActiveOzonAccountsQuery;
use App\Ingestion\Application\NotifyStaleAccountsAction;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceRawDocumentRepository;
use App\Ingestion\Infrastructure\Query\RecentlyIngestedAccountsQuery;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class NotifyStaleAccountsActionTest extends KernelTestCase
{
    public function testActiveAccountWithoutRecentDataIsReported(): void
    {
        $container = $this->bootedContainer();
        $account = $this->activeAccount($container);
        // Ни одного raw-документа: синхронизация не проходит вовсе —
        // ни ошибки в трекере, ни пустого экрана у клиента не будет,
        // цифры просто останутся вчерашними.

        $mailer = $this->recordingMailer();
        $alerted = ($this->action($container, $mailer))();

        self::assertSame([$this->key($account)], $alerted);
        self::assertCount(1, $mailer->messages);

        $email = $mailer->messages[0] ?? null;
        self::assertInstanceOf(Email::class, $email);
        self::assertStringContainsString($this->key($account), (string) $email->getTextBody());
        $to = $email->getTo();
        self::assertNotSame([], $to);
        self::assertSame('ops@example.test', $to[0]->getAddress());
    }

    public function testAccountWithRecentDataIsSilent(): void
    {
        $container = $this->bootedContainer();
        $account = $this->activeAccount($container);

        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withCompanyId($account->companyId())
            ->withMarketplaceAccountId($account->id())
            ->withReceivedAt(new \DateTimeImmutable('-1 hour'))
            ->persistWith(new DoctrineMarketplaceRawDocumentRepository($this->connection($container)));

        $mailer = $this->recordingMailer();
        $alerted = ($this->action($container, $mailer))();

        self::assertSame([], $alerted);
        self::assertSame([], $mailer->messages);
    }

    public function testSecondTickDoesNotRepeatTheSameLetter(): void
    {
        $container = $this->bootedContainer();
        $this->activeAccount($container);

        // Проверка идёт раз в час, а сломанная синхронизация чинится
        // не мгновенно: без подавления один и тот же отказ дал бы
        // двадцать четыре письма в сутки, и следующая настоящая тревога
        // потерялась бы среди повторов.
        $mailer = $this->recordingMailer();
        $action = $this->action($container, $mailer);

        $action();
        $repeated = $action();

        self::assertSame([], $repeated);
        self::assertCount(1, $mailer->messages);
    }

    public function testFailedSendDoesNotSuppressTheNextAttempt(): void
    {
        $container = $this->bootedContainer();
        $account = $this->activeAccount($container);

        // SMTP бывает временно недоступен. Подавление повторов не должно
        // срабатывать на письмо, которое не ушло: иначе один отказ
        // провайдера стоил бы суток тишины ровно тогда, когда данные
        // уже встали.
        $failing = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new \RuntimeException('SMTP недоступен');
            }
        };

        $locks = new LockFactory(new InMemoryStore());

        try {
            ($this->action($container, $failing, $locks))();
            self::fail('Отказ отправки обязан быть громким.');
        } catch (\RuntimeException $expected) {
            self::assertSame('SMTP недоступен', $expected->getMessage());
        }

        $mailer = $this->recordingMailer();
        $retried = ($this->action($container, $mailer, $locks))();

        self::assertSame([$this->key($account)], $retried);
        self::assertCount(1, $mailer->messages);
    }

    private function action(ContainerInterface $container, MailerInterface $mailer, ?LockFactory $locks = null): NotifyStaleAccountsAction
    {
        $connection = $this->connection($container);

        return new NotifyStaleAccountsAction(
            new IdentityScheduleFacade(new ActiveOzonAccountsQuery($connection)),
            new RecentlyIngestedAccountsQuery($connection),
            $mailer,
            // InMemoryStore, а не боевой LOCK_DSN: flock в тестовой среде
            // отпускает замок вместе с процессом и посуточное подавление
            // не удержал бы, а Redis для одного теста поднимать незачем.
            // В проде LOCK_DSN — redis (docker-compose.prod.yml), и там
            // замок переживает конец тика по TTL.
            $locks ?? new LockFactory(new InMemoryStore()),
            'ops@example.test',
            'smtp://mail.example.test',
        );
    }

    private function activeAccount(ContainerInterface $container): MarketplaceAccount
    {
        /** @var CompanyRepository $companies */
        $companies = $container->get(CompanyRepository::class);
        /** @var MarketplaceAccountRepository $marketplaceAccounts */
        $marketplaceAccounts = $container->get(MarketplaceAccountRepository::class);

        return MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->persistWith($companies, $marketplaceAccounts);
    }

    private function key(MarketplaceAccount $account): string
    {
        return RecentlyIngestedAccountsQuery::key(
            $account->companyId()->toRfc4122(),
            $account->id()->toRfc4122(),
        );
    }

    private function connection(ContainerInterface $container): Connection
    {
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        return $connection;
    }

    /**
     * Записывающая заглушка вместо почтового сервиса контейнера: письмо
     * здесь — проверяемый результат, а не побочный эффект, и брать его
     * из тестовой обвязки фреймворка значило бы проверять её, а не нас.
     *
     * @return MailerInterface&object{messages: list<RawMessage>}
     */
    private function recordingMailer(): MailerInterface
    {
        return new class implements MailerInterface {
            /** @var list<RawMessage> */
            public array $messages = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->messages[] = $message;
            }
        };
    }

    private function bootedContainer(): ContainerInterface
    {
        self::bootKernel();

        return self::getContainer();
    }
}
