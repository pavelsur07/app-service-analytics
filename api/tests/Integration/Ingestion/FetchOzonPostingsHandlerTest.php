<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Application\RegisterCompanyWithOzonAccountAction;
use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use App\Ingestion\Application\MessageHandler\FetchOzonPostingsHandler;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonPostingFboListClient;
use App\Tests\Support\Fake\FakeOzonPostingsFetcher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Клиент -> raw -> парсер -> upsert facts, целиком через реальный Postgres
 * и реальную расшифровку credentials — подменяется только HTTP (ADR-005).
 * Идемпотентность (CLAUDE.md §9, обязательное покрытие): второй прогон
 * обработчика на тех же входных данных не меняет результат.
 */
final class FetchOzonPostingsHandlerTest extends KernelTestCase
{
    private const string FIXTURE = __DIR__.'/../../Fixtures/Marketplace/ozon/posting-fbo-list-2026-07-01.json';

    public function testHandlingTheSameMessageTwiceDoesNotDuplicateRawOrFacts(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $fixtureBody = file_get_contents(self::FIXTURE);
        self::assertIsString($fixtureBody);

        /** @var RegisterCompanyWithOzonAccountAction $registerAccount */
        $registerAccount = $container->get(RegisterCompanyWithOzonAccountAction::class);
        $account = ($registerAccount)('Sandbox LLC', 'shop-1', ['client_id' => 'shop-1', 'api_key' => 'key-1']);

        // Реальный клиент подменяется фейком под service id конкретного
        // класса — интерфейс OzonPostingsFetcher резолвится в тот же id,
        // так его получит и обработчик через DI.
        $container->set(OzonPostingFboListClient::class, new FakeOzonPostingsFetcher($fixtureBody));

        /** @var FetchOzonPostingsHandler $handler */
        $handler = $container->get(FetchOzonPostingsHandler::class);

        $message = new FetchOzonPostingsMessage(
            companyId: $account->companyId()->toRfc4122(),
            marketplaceAccountId: $account->id()->toRfc4122(),
            businessDate: '2026-07-01',
        );

        ($handler)($message);

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $rawCountAfterFirst = $connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_raw_document WHERE company_id = ?',
            [$account->companyId()->toRfc4122()],
        );
        $factCountAfterFirst = $connection->fetchOne(
            'SELECT COUNT(*) FROM sales_fact WHERE company_id = ?',
            [$account->companyId()->toRfc4122()],
        );

        // Повторный запуск на тех же входных данных — идемпотентен (ADR-006).
        ($handler)($message);

        $rawCountAfterSecond = $connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_raw_document WHERE company_id = ?',
            [$account->companyId()->toRfc4122()],
        );
        $factCountAfterSecond = $connection->fetchOne(
            'SELECT COUNT(*) FROM sales_fact WHERE company_id = ?',
            [$account->companyId()->toRfc4122()],
        );

        self::assertEquals(1, $rawCountAfterFirst);
        self::assertEquals(86, $factCountAfterFirst);
        self::assertEquals($rawCountAfterFirst, $rawCountAfterSecond);
        self::assertEquals($factCountAfterFirst, $factCountAfterSecond);
    }

    public function testFactsReferenceThePersistedRawDocument(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $fixtureBody = file_get_contents(self::FIXTURE);
        self::assertIsString($fixtureBody);

        /** @var RegisterCompanyWithOzonAccountAction $registerAccount */
        $registerAccount = $container->get(RegisterCompanyWithOzonAccountAction::class);
        $account = ($registerAccount)('Sandbox LLC', 'shop-2', ['client_id' => 'shop-2', 'api_key' => 'key-2']);

        $container->set(OzonPostingFboListClient::class, new FakeOzonPostingsFetcher($fixtureBody));

        /** @var FetchOzonPostingsHandler $handler */
        $handler = $container->get(FetchOzonPostingsHandler::class);

        ($handler)(new FetchOzonPostingsMessage(
            companyId: $account->companyId()->toRfc4122(),
            marketplaceAccountId: $account->id()->toRfc4122(),
            businessDate: '2026-07-01',
        ));

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
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
}
