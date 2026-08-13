<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceListingWriter;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineMarketplaceListingWriterTest extends KernelTestCase
{
    public function testRepeatedSyncKeepsFirstSeenAndAdvancesLastSeen(): void
    {
        self::bootKernel();
        $writer = $this->writer();

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $first = new \DateTimeImmutable('2026-08-13 10:00:00');
        $second = new \DateTimeImmutable('2026-08-14 10:00:00');

        $this->sync($writer, $companyId, $accountId, ['111'], $first);
        $this->sync($writer, $companyId, $accountId, ['111'], $second);

        $row = $this->row($companyId, '111');
        self::assertNotNull($row);
        self::assertIsString($row['first_seen_at']);
        self::assertIsString($row['last_seen_at']);
        // Товар, который мы уже видели, не становится новым от того,
        // что синхронизация прошла снова.
        self::assertStringStartsWith('2026-08-13 10:00:00', $row['first_seen_at']);
        self::assertStringStartsWith('2026-08-14 10:00:00', $row['last_seen_at']);
    }

    public function testVanishedListingIsRemoved(): void
    {
        self::bootKernel();
        $writer = $this->writer();

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();

        $this->sync($writer, $companyId, $accountId, ['111', '222'], new \DateTimeImmutable('2026-08-13 10:00:00'));
        // Во второй синхронизации площадка отдала только один товар:
        // второй снят с продажи и «своим» для оверлея быть перестаёт.
        $this->sync($writer, $companyId, $accountId, ['111'], new \DateTimeImmutable('2026-08-14 10:00:00'));

        self::assertNotNull($this->row($companyId, '111'));
        self::assertNull($this->row($companyId, '222'));
    }

    public function testSyncOfOneAccountDoesNotTouchAnother(): void
    {
        self::bootKernel();
        $writer = $this->writer();

        $companyId = Uuid::v7();
        $ours = Uuid::v7();
        $theirs = Uuid::v7();

        $this->sync($writer, $companyId, $theirs, ['999'], new \DateTimeImmutable('2026-08-13 10:00:00'));
        // Удаление исчезнувших ограничено своим подключением: иначе
        // синхронизация одного кабинета вычищала бы каталог другого.
        $this->sync($writer, $companyId, $ours, ['111'], new \DateTimeImmutable('2026-08-14 10:00:00'));

        self::assertNotNull($this->row($companyId, '999'));
        self::assertNotNull($this->row($companyId, '111'));
    }

    public function testCompanyIsNotTouchedByAnotherCompanySync(): void
    {
        self::bootKernel();
        $writer = $this->writer();

        // Обязательное покрытие ADR-005: изоляция данных между компаниями.
        // Артикул один и тот же — товары площадки общие для всех продавцов,
        // и отделить их может только company_id в самом SQL.
        $ours = Uuid::v7();
        $theirs = Uuid::v7();
        $accountId = Uuid::v7();

        $this->sync($writer, $theirs, $accountId, ['111'], new \DateTimeImmutable('2026-08-13 10:00:00'));
        $this->sync($writer, $ours, $accountId, ['222'], new \DateTimeImmutable('2026-08-14 10:00:00'));

        self::assertNotNull($this->row($theirs, '111'));
        self::assertNotNull($this->row($ours, '222'));
        self::assertNull($this->row($ours, '111'));
    }

    public function testEmptyCatalogEmptiesTheAccount(): void
    {
        self::bootKernel();
        $writer = $this->writer();

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();

        $this->sync($writer, $companyId, $accountId, ['111'], new \DateTimeImmutable('2026-08-13 10:00:00'));
        // Пустой список — валидный ответ площадки (все товары сняты),
        // и он обязан очистить каталог. Защита от неполной выгрузки
        // не здесь: сюда список попадает, только когда пройдены все
        // страницы (см. докблок репозитория).
        $this->sync($writer, $companyId, $accountId, [], new \DateTimeImmutable('2026-08-14 10:00:00'));

        self::assertNull($this->row($companyId, '111'));
    }

    /**
     * @param list<string> $skus
     */
    private function sync(
        DoctrineMarketplaceListingWriter $writer,
        Uuid $companyId,
        Uuid $accountId,
        array $skus,
        \DateTimeImmutable $syncedAt,
    ): void {
        $listings = array_map(
            static fn (string $sku) => MarketplaceListingBuilder::aMarketplaceListing()
                ->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)
                ->withMarketplaceSku($sku)
                ->withSeenAt($syncedAt)
                ->build(),
            $skus,
        );

        $writer->replaceForAccount($companyId->toRfc4122(), $accountId, $listings, $syncedAt);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(Uuid $companyId, string $sku): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT first_seen_at, last_seen_at FROM marketplace_listing WHERE company_id = ? AND marketplace_sku = ?',
            [$companyId->toRfc4122(), $sku],
        );

        return false === $row ? null : $row;
    }

    private function writer(): DoctrineMarketplaceListingWriter
    {
        return new DoctrineMarketplaceListingWriter($this->connection());
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
