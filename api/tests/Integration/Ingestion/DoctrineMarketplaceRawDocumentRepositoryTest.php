<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceRawDocumentRepository;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-006: повторное получение идентичного ответа не создаёт новой строки;
 * изменившийся ответ того же периода — создаёт (новый body_hash).
 */
final class DoctrineMarketplaceRawDocumentRepositoryTest extends KernelTestCase
{
    public function testIdenticalContentForSamePeriodIsNotDuplicated(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // Не $container->get() концретного класса: у него нет ни одного
        // потребителя до пакета 5 (async-сценарий), и компилятор контейнера
        // вычищает неиспользуемые private-сервисы при сборке. Реальное
        // DBAL-соединение из контейнера — достаточно для интеграционного теста.
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $repository = new DoctrineMarketplaceRawDocumentRepository($connection);

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();

        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withRawBody('{"result":[{"posting_number":"A-1"}]}')
            ->persistWith($repository);

        // Тот же период, тот же контент — повторная синхронизация того же
        // дня без изменений на площадке.
        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withRawBody('{"result":[{"posting_number":"A-1"}]}')
            ->persistWith($repository);

        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_raw_document WHERE company_id = ? AND marketplace_account_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122()],
        );

        self::assertEquals(1, $count);
    }

    public function testChangedContentForSamePeriodCreatesNewRow(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // Не $container->get() концретного класса: у него нет ни одного
        // потребителя до пакета 5 (async-сценарий), и компилятор контейнера
        // вычищает неиспользуемые private-сервисы при сборке. Реальное
        // DBAL-соединение из контейнера — достаточно для интеграционного теста.
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $repository = new DoctrineMarketplaceRawDocumentRepository($connection);

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();

        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withRawBody('{"result":[{"posting_number":"A-1"}]}')
            ->persistWith($repository);

        // Тот же период, другой контент — площадка доначислила ещё одну
        // строку в отчёте за тот же день.
        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withRawBody('{"result":[{"posting_number":"A-1"},{"posting_number":"A-2"}]}')
            ->persistWith($repository);

        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_raw_document WHERE company_id = ? AND marketplace_account_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122()],
        );

        self::assertEquals(2, $count);
    }
}
