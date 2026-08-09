<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use App\Ingestion\Application\MessageHandler\FetchOzonPostingsHandler;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonPostingFboListClient;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Fake\FakeOzonPostingsFetcher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Клиент -> raw -> парсер -> upsert facts, целиком через реальный Postgres
 * и реальную расшифровку credentials — подменяется только HTTP (ADR-005).
 */
final class FetchOzonPostingsHandlerTest extends KernelTestCase
{
    private const string FIXTURE = __DIR__.'/../../Fixtures/Marketplace/ozon/posting-fbo-list-2026-07-01.json';

    public function testHandlingTheSameMessageTwiceLeavesFactsAndRawDocumentUntouched(): void
    {
        $container = $this->bootedContainer();
        $fixtureBody = $this->fixtureBody();

        $companies = $this->companies($container);
        $marketplaceAccounts = $this->marketplaceAccounts($container);
        $account = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-1')
            ->withPlaintextCredentials(['client_id' => 'shop-1', 'api_key' => 'key-1'], $this->credentialsEncryptor($container))
            ->persistWith($companies, $marketplaceAccounts);

        $container->set(OzonPostingFboListClient::class, new FakeOzonPostingsFetcher($fixtureBody));
        /** @var FetchOzonPostingsHandler $handler */
        $handler = $container->get(FetchOzonPostingsHandler::class);
        $message = new FetchOzonPostingsMessage(
            companyId: $account->companyId()->toRfc4122(),
            marketplaceAccountId: $account->id()->toRfc4122(),
            businessDate: '2026-07-01',
        );

        ($handler)($message);

        $connection = $this->connection($container);
        $rawRowAfterFirst = $this->soleRawDocumentRow($connection, $account);
        $factRowAfterFirst = $this->oneFactRow($connection, $account, '40705738-0407-1|4404411581');
        $factCountAfterFirst = $this->factCount($connection, $account);

        // Повторный запуск на тех же входных данных — идемпотентен
        // (ADR-006, CLAUDE.md §9): не только число строк совпадает,
        // но и сама строка — тот же raw_document_id, тот же first_loaded_at.
        // Проверка только COUNT(*) пропустила бы подмену содержимого
        // строки при неизменном количестве.
        ($handler)($message);

        $rawRowAfterSecond = $this->soleRawDocumentRow($connection, $account);
        $factRowAfterSecond = $this->oneFactRow($connection, $account, '40705738-0407-1|4404411581');
        $factCountAfterSecond = $this->factCount($connection, $account);

        self::assertSame(86, $factCountAfterFirst);
        self::assertSame($factCountAfterFirst, $factCountAfterSecond);
        self::assertSame($rawRowAfterFirst, $rawRowAfterSecond);
        self::assertSame($factRowAfterFirst, $factRowAfterSecond);
    }

    public function testFactsReferenceThePersistedRawDocument(): void
    {
        $container = $this->bootedContainer();
        $fixtureBody = $this->fixtureBody();

        $companies = $this->companies($container);
        $marketplaceAccounts = $this->marketplaceAccounts($container);
        $account = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-2')
            ->withPlaintextCredentials(['client_id' => 'shop-2', 'api_key' => 'key-2'], $this->credentialsEncryptor($container))
            ->persistWith($companies, $marketplaceAccounts);

        $container->set(OzonPostingFboListClient::class, new FakeOzonPostingsFetcher($fixtureBody));
        /** @var FetchOzonPostingsHandler $handler */
        $handler = $container->get(FetchOzonPostingsHandler::class);

        ($handler)(new FetchOzonPostingsMessage(
            companyId: $account->companyId()->toRfc4122(),
            marketplaceAccountId: $account->id()->toRfc4122(),
            businessDate: '2026-07-01',
        ));

        $connection = $this->connection($container);
        $orphanFactCount = $connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM sales_fact f
                WHERE f.company_id = ?
                  AND NOT EXISTS (
                      SELECT 1 FROM marketplace_raw_document d WHERE d.id = f.raw_document_id
                  )
                SQL,
            [$account->companyId()->toRfc4122()],
        );

        self::assertEquals(0, $orphanFactCount);
    }

    public function testMarketplaceAccountOfAnotherCompanyIsNotSyncable(): void
    {
        $container = $this->bootedContainer();
        $fixtureBody = $this->fixtureBody();

        $companies = $this->companies($container);
        $marketplaceAccounts = $this->marketplaceAccounts($container);

        // Компания B пытается синхронизировать подключение, которое
        // на самом деле принадлежит компании A — companyId и
        // marketplaceAccountId не согласованы (CLAUDE.md §1: поиск
        // по одному лишь id запрещён, изоляция арендаторов проверяется
        // в каждом методе чтения).
        $companyA = CompanyBuilder::aCompany()->persistWith($companies);
        $companyB = CompanyBuilder::aCompany()->persistWith($companies);
        $accountOfCompanyA = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($companyA)
            ->withExternalShopId('shop-3')
            ->withPlaintextCredentials(['client_id' => 'shop-3', 'api_key' => 'key-3'], $this->credentialsEncryptor($container))
            ->persistWith($companies, $marketplaceAccounts);

        $container->set(OzonPostingFboListClient::class, new FakeOzonPostingsFetcher($fixtureBody));
        /** @var FetchOzonPostingsHandler $handler */
        $handler = $container->get(FetchOzonPostingsHandler::class);

        $this->expectException(\RuntimeException::class);

        ($handler)(new FetchOzonPostingsMessage(
            companyId: $companyB->id()->toRfc4122(),
            marketplaceAccountId: $accountOfCompanyA->id()->toRfc4122(),
            businessDate: '2026-07-01',
        ));
    }

    public function testBrokenMarketplaceAccountIsNotSynced(): void
    {
        $container = $this->bootedContainer();
        $fixtureBody = $this->fixtureBody();

        $companies = $this->companies($container);
        $marketplaceAccounts = $this->marketplaceAccounts($container);
        $account = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-4')
            ->withPlaintextCredentials(['client_id' => 'shop-4', 'api_key' => 'key-4'], $this->credentialsEncryptor($container))
            ->withState(MarketplaceAccountState::Broken)
            ->persistWith($companies, $marketplaceAccounts);

        $container->set(OzonPostingFboListClient::class, new FakeOzonPostingsFetcher($fixtureBody));
        /** @var FetchOzonPostingsHandler $handler */
        $handler = $container->get(FetchOzonPostingsHandler::class);

        $this->expectException(\RuntimeException::class);

        try {
            ($handler)(new FetchOzonPostingsMessage(
                companyId: $account->companyId()->toRfc4122(),
                marketplaceAccountId: $account->id()->toRfc4122(),
                businessDate: '2026-07-01',
            ));
        } finally {
            $connection = $this->connection($container);
            $rawCount = $connection->fetchOne(
                'SELECT COUNT(*) FROM marketplace_raw_document WHERE company_id = ?',
                [$account->companyId()->toRfc4122()],
            );
            self::assertEquals(0, $rawCount, 'broken-подключение не должно доходить до HTTP-запроса');
        }
    }

    private function bootedContainer(): ContainerInterface
    {
        self::bootKernel();

        return self::getContainer();
    }

    private function fixtureBody(): string
    {
        $body = file_get_contents(self::FIXTURE);
        self::assertIsString($body);

        return $body;
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

    private function credentialsEncryptor(ContainerInterface $container): MarketplaceCredentialsEncryptor
    {
        /** @var MarketplaceCredentialsEncryptor $encryptor */
        $encryptor = $container->get(MarketplaceCredentialsEncryptor::class);

        return $encryptor;
    }

    private function connection(ContainerInterface $container): Connection
    {
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        return $connection;
    }

    private function factCount(Connection $connection, MarketplaceAccount $account): int
    {
        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM sales_fact WHERE company_id = ?',
            [$account->companyId()->toRfc4122()],
        );

        \assert(\is_int($count) || \is_string($count));

        return (int) $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function soleRawDocumentRow(Connection $connection, MarketplaceAccount $account): array
    {
        $row = $connection->fetchAssociative(
            'SELECT id, body_hash, received_at FROM marketplace_raw_document WHERE company_id = ?',
            [$account->companyId()->toRfc4122()],
        );
        self::assertNotFalse($row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function oneFactRow(Connection $connection, MarketplaceAccount $account, string $sourceRowId): array
    {
        $row = $connection->fetchAssociative(
            <<<'SQL'
                SELECT status, amount_minor, commission_amount_minor, raw_document_id, row_hash, first_loaded_at, last_updated_at
                FROM sales_fact
                WHERE company_id = ? AND source_row_id = ?
                SQL,
            [$account->companyId()->toRfc4122(), $sourceRowId],
        );
        self::assertNotFalse($row);

        return $row;
    }
}
