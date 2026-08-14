<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\CompanyMemberRepository;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\UserRepository;
use App\Ingestion\Application\Message\FetchOzonExpensesMessage;
use App\Ingestion\Application\MessageHandler\FetchOzonExpensesHandler;
use App\Ingestion\Domain\OzonExpensesFetcher;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonAccrualByDayClient;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Клиент -> raw -> парсер -> upsert расходов, через реальный Postgres
 * и реальную расшифровку credentials; подменяется только HTTP (ADR-005).
 */
final class FetchOzonExpensesHandlerTest extends KernelTestCase
{
    private const string FIXTURE = __DIR__.'/../../Fixtures/Marketplace/ozon/finance-accrual-by-day-2026-07.json';

    public function testExpensesOfARealCabinetDayAreStored(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $this->fetcher($container, [$this->fixtureBody()]);

        $this->sync($container, $account);

        // Настоящий день кабинета: расходы по отправлениям, по товарам
        // и общие — все в одной таблице.
        self::assertGreaterThan(200, $this->expenseCount($container, $account));
        self::assertSame(9, $this->generalExpenseCount($container, $account));
    }

    public function testRepeatedDayChangesNothing(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $body = $this->fixtureBody();
        $this->fetcher($container, [$body, $body]);

        $this->sync($container, $account);
        $afterFirst = $this->rows($container, $account);

        // Идемпотентность (CLAUDE.md §4, §9): повторная загрузка того же
        // дня не заводит вторых строк и не трогает существующие —
        // ни сумму, ни first_loaded_at.
        $this->sync($container, $account);

        self::assertSame($afterFirst, $this->rows($container, $account));
    }

    public function testChangedAmountUpdatesTheRowInPlace(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $this->fetcher($container, [
            $this->day([['accrual_id' => 55123734698, 'sku' => '111', 'type_id' => 32, 'amount' => '-115']]),
            $this->day([['accrual_id' => 55123734698, 'sku' => '111', 'type_id' => 32, 'amount' => '-95.50']]),
        ]);

        $this->sync($container, $account);
        $this->sync($container, $account);

        // Площадка пересчитала начисление задним числом (ADR-006):
        // строка обновляется, а не задваивается.
        self::assertSame(1, $this->expenseCount($container, $account));
        self::assertSame(-9550, $this->amountOf($container, $account, '55123734698|111|32'));
    }

    public function testAllPagesOfADayAreRead(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $fetcher = $this->fetcher($container, [
            $this->day([['accrual_id' => 1, 'sku' => '111', 'type_id' => 32, 'amount' => '-10']], 'cursor-2'),
            $this->day([['accrual_id' => 2, 'sku' => '222', 'type_id' => 1, 'amount' => '-20']]),
        ]);

        $this->sync($container, $account);

        // Курсор внутри дня: страница с непустым last_id означает,
        // что день прочитан не весь, и остановка на ней потеряла бы
        // расходы молча.
        self::assertSame(['', 'cursor-2'], $fetcher->cursors);
        self::assertSame(2, $this->expenseCount($container, $account));
    }

    public function testExpensesOfAnotherCompanyAreNotVisible(): void
    {
        $container = $this->bootedContainer();
        $ours = $this->account($container);
        $theirs = $this->account($container);
        $this->fetcher($container, [
            $this->day([['accrual_id' => 1, 'sku' => '111', 'type_id' => 32, 'amount' => '-10']]),
            $this->day([['accrual_id' => 1, 'sku' => '111', 'type_id' => 32, 'amount' => '-77']]),
        ]);

        // Обязательное покрытие ADR-005. Идентификатор начисления
        // у площадки один на кабинет, и совпадение accrual_id у двух
        // компаний не должно склеивать их расходы: разделяет только
        // company_id в самом ключе.
        $this->sync($container, $ours);
        $this->sync($container, $theirs);

        self::assertSame(-1000, $this->amountOf($container, $ours, '1|111|32'));
        self::assertSame(-7700, $this->amountOf($container, $theirs, '1|111|32'));
    }

    public function testAuthorizationFailureBreaksTheAccount(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);

        $container->set(OzonAccrualByDayClient::class, new class implements OzonExpensesFetcher {
            public function fetchDay(string $clientId, string $apiKey, \DateTimeImmutable $day, string $lastId): string
            {
                // Настоящее исключение symfony/http-client на 401,
                // а не выдуманное: распознавание отказа авторизации
                // смотрит на код ответа внутри исключения.
                $client = new MockHttpClient(new MockResponse('{"code":16}', ['http_code' => 401]));

                return $client->request('POST', 'https://api-seller.ozon.ru/v1/finance/accrual/by-day')->getContent();
            }
        });

        $this->sync($container, $account);

        // Отказ авторизации — событие жизненного цикла подключения
        // (ADR-007), а не повод ретраить: подключение переводится
        // в broken, расходы не пишутся.
        $state = $this->connection($container)->fetchOne(
            'SELECT state FROM marketplace_account WHERE id = ?',
            [$account->id()->toRfc4122()],
        );
        self::assertSame('broken', $state);
        self::assertSame(0, $this->expenseCount($container, $account));
    }

    /**
     * @param list<array{accrual_id: int, sku: string, type_id: int, amount: string}> $rows
     */
    private function day(array $rows, string $lastId = ''): string
    {
        $accruals = array_map(
            static fn (array $row): array => [
                'accrual_id' => $row['accrual_id'],
                'date' => '2026-07-01',
                'unit_number' => 'unit-'.$row['accrual_id'],
                'accrued_category' => 'ITEM',
                'total_amount' => ['amount' => $row['amount'], 'currency' => 'RUB'],
                'posting' => null,
                'item_fees' => ['fees' => [[
                    'sku' => (int) $row['sku'],
                    'fees' => [['type_id' => $row['type_id'], 'accrued' => ['amount' => $row['amount'], 'currency' => 'RUB']]],
                ]]],
                'non_item_fee' => null,
                'container_fees' => null,
            ],
            $rows,
        );

        return json_encode(['accruals' => $accruals, 'last_id' => $lastId], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $pages
     *
     * @return OzonExpensesFetcher&object{cursors: list<string>}
     */
    private function fetcher(ContainerInterface $container, array $pages): OzonExpensesFetcher
    {
        $fetcher = new class($pages) implements OzonExpensesFetcher {
            /** @var list<string> */
            public array $cursors = [];

            /** @param list<string> $pages */
            public function __construct(private array $pages)
            {
            }

            public function fetchDay(string $clientId, string $apiKey, \DateTimeImmutable $day, string $lastId): string
            {
                $this->cursors[] = $lastId;
                $page = array_shift($this->pages);
                if (null === $page) {
                    throw new \LogicException('Обработчик запросил больше страниц, чем задано в тесте.');
                }

                return $page;
            }
        };

        $container->set(OzonAccrualByDayClient::class, $fetcher);

        return $fetcher;
    }

    private function sync(ContainerInterface $container, MarketplaceAccount $account): void
    {
        /** @var FetchOzonExpensesHandler $handler */
        $handler = $container->get(FetchOzonExpensesHandler::class);
        ($handler)(new FetchOzonExpensesMessage(
            companyId: $account->companyId()->toRfc4122(),
            marketplaceAccountId: $account->id()->toRfc4122(),
            accrualDate: '2026-07-01',
        ));
    }

    private function expenseCount(ContainerInterface $container, MarketplaceAccount $account): int
    {
        $count = $this->connection($container)->fetchOne(
            'SELECT COUNT(*) FROM marketplace_expense_fact WHERE company_id = ? AND marketplace_account_id = ?',
            [$account->companyId()->toRfc4122(), $account->id()->toRfc4122()],
        );
        self::assertIsInt($count);

        return $count;
    }

    private function generalExpenseCount(ContainerInterface $container, MarketplaceAccount $account): int
    {
        $count = $this->connection($container)->fetchOne(
            "SELECT COUNT(*) FROM marketplace_expense_fact WHERE company_id = ? AND marketplace_sku = ''",
            [$account->companyId()->toRfc4122()],
        );
        self::assertIsInt($count);

        return $count;
    }

    private function amountOf(ContainerInterface $container, MarketplaceAccount $account, string $sourceRowId): int
    {
        $amount = $this->connection($container)->fetchOne(
            'SELECT amount_minor FROM marketplace_expense_fact WHERE company_id = ? AND source_row_id = ?',
            [$account->companyId()->toRfc4122(), $sourceRowId],
        );
        self::assertIsInt($amount);

        return $amount;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(ContainerInterface $container, MarketplaceAccount $account): array
    {
        return $this->connection($container)->fetchAllAssociative(
            'SELECT source_row_id, amount_minor, first_loaded_at, last_updated_at FROM marketplace_expense_fact WHERE company_id = ? ORDER BY source_row_id',
            [$account->companyId()->toRfc4122()],
        );
    }

    private function account(ContainerInterface $container): MarketplaceAccount
    {
        /** @var CompanyRepository $companies */
        $companies = $container->get(CompanyRepository::class);
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = $container->get(MarketplaceAccountRepository::class);
        /** @var MarketplaceCredentialsEncryptor $encryptor */
        $encryptor = $container->get(MarketplaceCredentialsEncryptor::class);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        /** @var CompanyMemberRepository $members */
        $members = $container->get(CompanyMemberRepository::class);

        // С участником: отказ авторизации порождает письмо клиенту
        // (ADR-007), и компания без адресата — ошибка данных,
        // а не сценарий.
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($company)
            ->withUser(UserBuilder::aUser()->withEmail('owner-'.bin2hex(random_bytes(4)).'@example.test')->persistWith($users))
            ->persistWith($companies, $users, $members);

        return MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->withExternalShopId('shop-'.bin2hex(random_bytes(4)))
            ->withPlaintextCredentials(['client_id' => 'shop-1', 'api_key' => 'key-1'], $encryptor)
            ->persistWith($companies, $accounts);
    }

    private function fixtureBody(): string
    {
        $body = file_get_contents(self::FIXTURE);
        self::assertIsString($body);

        return $body;
    }

    private function connection(ContainerInterface $container): Connection
    {
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        return $connection;
    }

    private function bootedContainer(): ContainerInterface
    {
        self::bootKernel();

        return self::getContainer();
    }
}
