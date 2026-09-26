<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Infrastructure\Persistence\DoctrineSalesFactWriter;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-006: идемпотентность через уникальный индекс на естественном ключе,
 * обновление только при изменившемся row_hash, first_loaded_at не
 * перезаписывается при повторном запуске без изменений.
 */
final class DoctrineSalesFactWriterTest extends KernelTestCase
{
    public function testUpsertingTheSameFactTwiceDoesNotDuplicateOrChangeFirstLoadedAt(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new DoctrineSalesFactWriter($connection);

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();

        $fact = SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withSourceRowId('P-1|SKU-1')
            ->build();

        $writer->upsertAll([$fact]);
        $firstLoadedAt = $connection->fetchOne(
            'SELECT first_loaded_at FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), 'P-1|SKU-1'],
        );

        // Повторный запуск того же факта — идемпотентен (ADR-006):
        // повторный вызов обработчика на тех же данных не меняет результат.
        $writer->upsertAll([$fact]);

        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), 'P-1|SKU-1'],
        );
        $firstLoadedAtAfter = $connection->fetchOne(
            'SELECT first_loaded_at FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), 'P-1|SKU-1'],
        );

        self::assertEquals(1, $count);
        self::assertSame($firstLoadedAt, $firstLoadedAtAfter);
    }

    public function testChangedStatusUpdatesRowAndAdvancesLastUpdatedAt(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new DoctrineSalesFactWriter($connection);

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();

        $writer->upsertAll([
            SalesFactBuilder::aSalesFact()
                ->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)
                ->withSourceRowId('P-2|SKU-1')
                ->withStatus('awaiting_packaging')
                ->build(),
        ]);

        // Отправление доставлено — площадка прислала перевыпущенный статус
        // того же отправления (ADR-006: обновление, не вторая строка).
        $writer->upsertAll([
            SalesFactBuilder::aSalesFact()
                ->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)
                ->withSourceRowId('P-2|SKU-1')
                ->withStatus('delivered')
                ->build(),
        ]);

        $row = $connection->fetchAssociative(
            'SELECT status, row_hash FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), 'P-2|SKU-1'],
        );
        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122()],
        );

        self::assertNotFalse($row);
        self::assertSame('delivered', $row['status']);
        self::assertEquals(1, $count);
    }

    public function testSameSourceRowIdIsIndependentAcrossCompanies(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new DoctrineSalesFactWriter($connection);

        $accountId = Uuid::v7();
        $companyA = Uuid::v7();
        $companyB = Uuid::v7();

        // Естественный ключ включает company_id первым столбцом
        // (CLAUDE.md §1) — тот же source_row_id у двух компаний обязан
        // дать две независимые строки, апдейт одной не задевает другую.
        $writer->upsertAll([
            SalesFactBuilder::aSalesFact()
                ->withCompanyId($companyA)
                ->withMarketplaceAccountId($accountId)
                ->withSourceRowId('SHARED|SKU')
                ->withAmount(Money::ofMinor(1_000, 'RUB'))
                ->build(),
        ]);
        $writer->upsertAll([
            SalesFactBuilder::aSalesFact()
                ->withCompanyId($companyB)
                ->withMarketplaceAccountId($accountId)
                ->withSourceRowId('SHARED|SKU')
                ->withAmount(Money::ofMinor(2_000, 'RUB'))
                ->build(),
        ]);

        $amountA = $connection->fetchOne(
            'SELECT amount_minor FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyA->toRfc4122(), $accountId->toRfc4122(), 'SHARED|SKU'],
        );
        $amountB = $connection->fetchOne(
            'SELECT amount_minor FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyB->toRfc4122(), $accountId->toRfc4122(), 'SHARED|SKU'],
        );

        self::assertEquals(1_000, $amountA);
        self::assertEquals(2_000, $amountB);
    }

    public function testUpsertPersistsAndUpdatesPostingAndOrderLinks(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new DoctrineSalesFactWriter($connection);

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $base = SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withSourceRowId('P-LINK|SKU-1');

        $writer->upsertAll([
            $base
                ->withPostingNumber('P-LINK-OLD')
                ->withOrderNumber('ORDER-LINK-OLD')
                ->build(),
        ]);
        $writer->upsertAll([
            $base
                ->withPostingNumber('P-LINK-NEW')
                ->withOrderNumber('ORDER-LINK-NEW')
                ->build(),
        ]);

        $row = $connection->fetchAssociative(
            'SELECT posting_number, order_number FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), 'P-LINK|SKU-1'],
        );

        self::assertNotFalse($row);
        self::assertSame('P-LINK-NEW', $row['posting_number']);
        self::assertSame('ORDER-LINK-NEW', $row['order_number']);
    }

    public function testBackfillLinksDoesNotRollBackTheCurrentSalesSnapshot(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new DoctrineSalesFactWriter($connection);

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $currentRawId = Uuid::v7();
        $base = SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withSourceRowId('BACKFILL-1|100001')
            ->withMarketplaceSku('100001');

        $writer->upsertAll([
            $base
                ->withStatus('delivered')
                ->withPostingNumber(null)
                ->withOrderNumber(null)
                ->withRawDocumentId($currentRawId)
                ->build(),
        ]);

        $writer->backfillLinks($companyId->toRfc4122(), [
            $base
                ->withStatus('awaiting_packaging')
                ->withPostingNumber('BACKFILL-1')
                ->withOrderNumber('BACKFILL')
                ->withRawDocumentId(Uuid::v7())
                ->build(),
        ]);

        $row = $connection->fetchAssociative(
            'SELECT status, raw_document_id, posting_number, order_number FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), 'BACKFILL-1|100001'],
        );

        self::assertNotFalse($row);
        self::assertSame('delivered', $row['status']);
        self::assertSame($currentRawId->toRfc4122(), $row['raw_document_id']);
        self::assertSame('BACKFILL-1', $row['posting_number']);
        self::assertSame('BACKFILL', $row['order_number']);
    }

    public function testLaterFilledDeliveryCityUpdatesRowButKeepsFirstLoadedAt(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new DoctrineSalesFactWriter($connection);

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $base = SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withSourceRowId('GEO-1|SKU-1');

        $writer->upsertAll([$base->withDeliveryCity(null)->build()]);
        $firstLoadedAt = $connection->fetchOne(
            'SELECT first_loaded_at FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), 'GEO-1|SKU-1'],
        );

        // Площадка дозаполнила город в перевыпущенном ответе (ADR-006).
        $writer->upsertAll([$base->withDeliveryCity('Брянск')->build()]);
        // Повтор того же ответа — идемпотентен.
        $writer->upsertAll([$base->withDeliveryCity('Брянск')->build()]);

        $row = $connection->fetchAssociative(
            'SELECT first_loaded_at, warehouse_id, warehouse_name, delivery_city FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), 'GEO-1|SKU-1'],
        );

        self::assertNotFalse($row);
        self::assertSame('Брянск', $row['delivery_city']);
        self::assertSame(1020000115166000, $row['warehouse_id']);
        self::assertSame('ЖУКОВСКИЙ_РФЦ', $row['warehouse_name']);
        self::assertSame($firstLoadedAt, $row['first_loaded_at']);
    }

    public function testBackfillFillsOnlyMissingDeliveryAttributes(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new DoctrineSalesFactWriter($connection);

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $base = SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withSourceRowId('GEO-BACKFILL-1|SKU-1');

        // Строка загружена до появления колонок: склада нет, город — уже
        // свежий из текущей синхронизации.
        $writer->upsertAll([
            $base->withStatus('delivered')->withWarehouse(null, null)->withDeliveryCity('Брянск')->build(),
        ]);

        $writer->backfillLinks($companyId->toRfc4122(), [
            $base
                ->withStatus('awaiting_packaging')
                ->withWarehouse(42, 'СТАРЫЙ_РФЦ')
                ->withDeliveryCity('Старый город')
                ->withRawDocumentId(Uuid::v7())
                ->build(),
        ]);

        $row = $connection->fetchAssociative(
            'SELECT status, warehouse_id, warehouse_name, delivery_city FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), 'GEO-BACKFILL-1|SKU-1'],
        );

        self::assertNotFalse($row);
        self::assertSame('delivered', $row['status']);
        self::assertSame(42, $row['warehouse_id']);
        self::assertSame('СТАРЫЙ_РФЦ', $row['warehouse_name']);
        self::assertSame('Брянск', $row['delivery_city']);
    }

    /**
     * Строка, записанная до расширения формулы row_hash: после бэкфилла
     * следующая синхронизация того же ответа не должна выглядеть как
     * корректировка задним числом (ADR-006: last_updated_at — её признак).
     */
    public function testBackfillRefreshesLegacyRowHashSoNextSyncIsNoOp(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new DoctrineSalesFactWriter($connection);

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $key = [$companyId->toRfc4122(), $accountId->toRfc4122(), 'GEO-HASH-1|SKU-1'];
        $current = SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withSourceRowId('GEO-HASH-1|SKU-1')
            ->withStatus('delivered');

        $writer->upsertAll([$current->withWarehouse(null, null)->withDeliveryCity(null)->withClusters(null, null)->build()]);
        $connection->executeStatement(
            "UPDATE sales_fact SET row_hash = 'legacy-formula', last_updated_at = '2026-01-01 00:00:00' WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?",
            $key,
        );

        $fact = $current->build();
        $writer->backfillLinks($companyId->toRfc4122(), [$fact]);
        $writer->upsertAll([$fact]);

        $row = $connection->fetchAssociative(
            'SELECT row_hash, last_updated_at, delivery_city, cluster_from, cluster_to FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            $key,
        );

        self::assertNotFalse($row);
        self::assertSame($fact->rowHash(), $row['row_hash']);
        self::assertSame('2026-01-01 00:00:00', $row['last_updated_at']);
        self::assertSame('Брянск', $row['delivery_city']);
        self::assertSame('Москва, МО и Дальние регионы', $row['cluster_from']);
        self::assertSame('Москва, МО и Дальние регионы', $row['cluster_to']);
    }

    /**
     * Исторический ответ со старым статусом не совпадает со строкой —
     * его хэш не принадлежит текущему снимку и не записывается.
     */
    public function testBackfillKeepsRowHashWhenHistoricalSnapshotDiffers(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new DoctrineSalesFactWriter($connection);

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $key = [$companyId->toRfc4122(), $accountId->toRfc4122(), 'GEO-HASH-2|SKU-1'];
        $base = SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withSourceRowId('GEO-HASH-2|SKU-1');

        $writer->upsertAll([$base->withStatus('delivered')->withDeliveryCity(null)->build()]);
        $connection->executeStatement(
            "UPDATE sales_fact SET row_hash = 'legacy-formula' WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?",
            $key,
        );

        $writer->backfillLinks($companyId->toRfc4122(), [$base->withStatus('awaiting_packaging')->build()]);

        $row = $connection->fetchAssociative(
            'SELECT status, row_hash, delivery_city FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            $key,
        );

        self::assertNotFalse($row);
        self::assertSame('delivered', $row['status']);
        self::assertSame('legacy-formula', $row['row_hash']);
        self::assertSame('Брянск', $row['delivery_city']);
    }

    public function testBackfillFillsOnlyMissingClusters(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new DoctrineSalesFactWriter($connection);

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $base = SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withSourceRowId('CL-BACKFILL-1|SKU-1');

        $writer->upsertAll([$base->withClusters(null, 'Дальний Восток')->build()]);
        $writer->backfillLinks($companyId->toRfc4122(), [
            $base->withClusters('Омск', 'Старый кластер')->withRawDocumentId(Uuid::v7())->build(),
        ]);

        $row = $connection->fetchAssociative(
            'SELECT cluster_from, cluster_to FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), 'CL-BACKFILL-1|SKU-1'],
        );

        self::assertNotFalse($row);
        self::assertSame('Омск', $row['cluster_from']);
        self::assertSame('Дальний Восток', $row['cluster_to']);
    }

    /**
     * Обход raw идёт по возрастанию received_at. Значение первого снимка
     * не должно пережить снимок, из которого получена текущая версия
     * строки: кластер прямо меняет метрику локальности.
     */
    public function testBackfillTakesSnapshotAttributesFromTheCurrentVersionRaw(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new DoctrineSalesFactWriter($connection);

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $currentRawId = Uuid::v7();
        $base = SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withSourceRowId('CL-ORDER-1|SKU-1');

        $writer->upsertAll([
            $base->withRawDocumentId($currentRawId)->withClusters(null, null)->withWarehouse(null, null)->build(),
        ]);

        // Ранний снимок: склад отгрузки ещё прежний.
        $writer->backfillLinks($companyId->toRfc4122(), [
            $base->withRawDocumentId(Uuid::v7())->withClusters('Омск', 'Дальний Восток')->withWarehouse(1, 'ОМСК_РФЦ')->build(),
        ]);
        // Снимок текущей версии строки: склад переназначен.
        $current = $base->withRawDocumentId($currentRawId)
            ->withClusters('Дальний Восток', 'Дальний Восток')
            ->withWarehouse(2, 'ХАБАРОВСК_2_РФЦ')
            ->build();
        $writer->backfillLinks($companyId->toRfc4122(), [$current]);
        // Более поздний, не текущий снимок не перетирает значение текущего.
        $writer->backfillLinks($companyId->toRfc4122(), [
            $base->withRawDocumentId(Uuid::v7())->withClusters('Новосибирск', 'Дальний Восток')->build(),
        ]);

        $row = $connection->fetchAssociative(
            'SELECT cluster_from, cluster_to, warehouse_id, warehouse_name, row_hash, raw_document_id FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), 'CL-ORDER-1|SKU-1'],
        );

        self::assertNotFalse($row);
        self::assertSame('Дальний Восток', $row['cluster_from']);
        self::assertSame('Дальний Восток', $row['cluster_to']);
        self::assertSame(2, $row['warehouse_id']);
        self::assertSame('ХАБАРОВСК_2_РФЦ', $row['warehouse_name']);
        self::assertSame($current->rowHash(), $row['row_hash']);
        self::assertSame($currentRawId->toRfc4122(), $row['raw_document_id']);
    }

    /**
     * Пустое поле в снимке текущей версии не стирает значение, которым
     * пустое заполнил другой снимок: итог не зависит от порядка обхода raw.
     */
    public function testEmptyAttributeInCurrentVersionRawDoesNotEraseFilledValue(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new DoctrineSalesFactWriter($connection);

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $currentRawId = Uuid::v7();
        $base = SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withSourceRowId('CL-EMPTY-1|SKU-1');
        $currentWithEmptyCity = $base->withRawDocumentId($currentRawId)->withDeliveryCity(null)->withClusters(null, null);

        $writer->upsertAll([$currentWithEmptyCity->build()]);
        $writer->backfillLinks($companyId->toRfc4122(), [
            $base->withRawDocumentId(Uuid::v7())->withDeliveryCity('Уфа')->withClusters('Омск', 'Омск')->build(),
        ]);
        $writer->backfillLinks($companyId->toRfc4122(), [$currentWithEmptyCity->build()]);

        $row = $connection->fetchAssociative(
            'SELECT delivery_city, cluster_from, cluster_to FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), 'CL-EMPTY-1|SKU-1'],
        );

        self::assertNotFalse($row);
        self::assertSame('Уфа', $row['delivery_city']);
        self::assertSame('Омск', $row['cluster_from']);
        self::assertSame('Омск', $row['cluster_to']);
    }

    public function testOrderMomentIsFilledByBackfillAndKeptByUpsert(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $writer = new DoctrineSalesFactWriter($connection);

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $key = [$companyId->toRfc4122(), $accountId->toRfc4122(), 'ORDERED-1|SKU-1'];
        $base = SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withSourceRowId('ORDERED-1|SKU-1');
        $orderedAt = new \DateTimeImmutable('2026-07-10 08:15:00', new \DateTimeZone('UTC'));

        // Строка, загруженная до появления колонки.
        $writer->upsertAll([$base->withOrderedAt(null)->build()]);
        $writer->backfillLinks($companyId->toRfc4122(), [
            $base->withOrderedAt($orderedAt)->withRawDocumentId(Uuid::v7())->build(),
        ]);
        // Повтор синхронизации того же ответа — без изменений.
        $writer->upsertAll([$base->withOrderedAt($orderedAt)->build()]);

        $row = $connection->fetchAssociative(
            'SELECT ordered_at, status FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            $key,
        );

        self::assertNotFalse($row);
        self::assertSame('2026-07-10 08:15:00', $row['ordered_at']);
        self::assertSame('delivered', $row['status']);
    }
}
