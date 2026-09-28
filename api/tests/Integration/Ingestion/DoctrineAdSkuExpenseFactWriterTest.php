<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Infrastructure\Persistence\DoctrineAdSkuExpenseFactWriter;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\AdSkuExpenseFactBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Запись рекламы по SKU (ADR-035 п. 2): upsert по естественному ключу,
 * обновление только при изменившейся сумме и не более старым ответом.
 */
final class DoctrineAdSkuExpenseFactWriterTest extends KernelTestCase
{
    public function testSameFactTwiceIsOneRowWithTheFirstLoadTimeKept(): void
    {
        [$connection, $writer] = $this->writer();
        $fact = AdSkuExpenseFactBuilder::anAdSkuExpenseFact()->build();

        $writer->upsertAll([$fact]);
        $first = $this->row($connection, $fact->companyId(), $fact->sourceRowIdValue());
        // Повтор того же ответа — идемпотентен (CLAUDE.md §4).
        $writer->upsertAll([$fact]);

        self::assertSame(1, $this->rowsOf($connection, $fact->companyId()));
        self::assertSame($first, $this->row($connection, $fact->companyId(), $fact->sourceRowIdValue()));
    }

    public function testNewerAnswerWithChangedAmountUpdatesTheRow(): void
    {
        [$connection, $writer] = $this->writer();
        $base = AdSkuExpenseFactBuilder::anAdSkuExpenseFact()->withCompanyId(Uuid::v7())->withMarketplaceAccountId(Uuid::v7());
        $older = $base->withSourceReceivedAt(new \DateTimeImmutable('2026-09-24 08:00:00'))->build();
        $newerRaw = Uuid::v7();

        $writer->upsertAll([$older]);
        // Корректировка задним числом: площадка пересчитала день.
        $writer->upsertAll([$base
            ->withAmount(Money::ofMinor(-100000, 'RUB'))
            ->withRawDocumentId($newerRaw)
            ->withSourceReceivedAt(new \DateTimeImmutable('2026-09-25 08:00:00'))
            ->build()]);

        $row = $this->row($connection, $older->companyId(), $older->sourceRowIdValue());
        self::assertSame(-100000, $row['amount_minor']);
        self::assertSame($newerRaw->toRfc4122(), $row['raw_document_id']);
        self::assertSame('2026-09-25 08:00:00', $row['source_received_at']);
    }

    public function testOlderAnswerProcessedLaterDoesNotOverwriteNewerNumbers(): void
    {
        [$connection, $writer] = $this->writer();
        $base = AdSkuExpenseFactBuilder::anAdSkuExpenseFact()->withCompanyId(Uuid::v7())->withMarketplaceAccountId(Uuid::v7());
        $newer = $base
            ->withAmount(Money::ofMinor(-100000, 'RUB'))
            ->withSourceReceivedAt(new \DateTimeImmutable('2026-09-25 08:00:00'))
            ->build();

        $writer->upsertAll([$newer]);
        // Периоды отчётов перекрываются, и очередь обработала старый
        // ответ после нового: побеждает полученный позже, а не
        // обработанный позже (ADR-035 п. 2).
        $writer->upsertAll([$base
            ->withAmount(Money::ofMinor(-119339, 'RUB'))
            ->withSourceReceivedAt(new \DateTimeImmutable('2026-09-24 08:00:00'))
            ->build()]);

        self::assertSame(-100000, $this->row($connection, $newer->companyId(), $newer->sourceRowIdValue())['amount_minor']);
    }

    public function testNewerAnswerWithTheSameAmountAdvancesTheReceivedMark(): void
    {
        [$connection, $writer] = $this->writer();
        $base = AdSkuExpenseFactBuilder::anAdSkuExpenseFact()->withCompanyId(Uuid::v7())->withMarketplaceAccountId(Uuid::v7());
        $first = $base->withSourceReceivedAt(new \DateTimeImmutable('2026-09-24 08:00:00'))->build();
        $confirmingRaw = Uuid::v7();

        $writer->upsertAll([$first]);
        $before = $this->row($connection, $first->companyId(), $first->sourceRowIdValue());
        // 26.09 площадка подтвердила ту же сумму.
        $writer->upsertAll([$base->withRawDocumentId($confirmingRaw)->withSourceReceivedAt(new \DateTimeImmutable('2026-09-26 08:00:00'))->build()]);
        // Ответ от 25.09 с другой суммой обработан последним: он старше
        // подтверждения и прежнюю сумму не возвращает.
        $writer->upsertAll([$base
            ->withAmount(Money::ofMinor(-100000, 'RUB'))
            ->withSourceReceivedAt(new \DateTimeImmutable('2026-09-25 08:00:00'))
            ->build()]);

        $after = $this->row($connection, $first->companyId(), $first->sourceRowIdValue());
        self::assertSame(-119339, $after['amount_minor']);
        self::assertSame('2026-09-26 08:00:00', $after['source_received_at']);
        self::assertSame($confirmingRaw->toRfc4122(), $after['raw_document_id']);
        // Данные не менялись — время последнего обновления прежнее (ADR-006).
        self::assertSame($before['last_updated_at'], $after['last_updated_at']);
    }

    public function testCorrectionToZeroOverwritesTheOldAmount(): void
    {
        [$connection, $writer] = $this->writer();
        $base = AdSkuExpenseFactBuilder::anAdSkuExpenseFact()->withCompanyId(Uuid::v7())->withMarketplaceAccountId(Uuid::v7());

        $writer->upsertAll([$base->build()]);
        $writer->upsertAll([$base
            ->withAmount(Money::ofMinor(0, 'RUB'))
            ->withSourceReceivedAt(new \DateTimeImmutable('2026-09-25 08:00:00'))
            ->build()]);

        self::assertSame(0, $this->row($connection, $base->build()->companyId(), $base->build()->sourceRowIdValue())['amount_minor']);
    }

    public function testOneSkuInTwoCampaignsIsTwoRows(): void
    {
        [$connection, $writer] = $this->writer();
        $base = AdSkuExpenseFactBuilder::anAdSkuExpenseFact()->withCompanyId(Uuid::v7())->withMarketplaceAccountId(Uuid::v7());

        $writer->upsertAll([
            $base->withCampaignId('14275771')->build(),
            $base->withCampaignId('16017246')->build(),
        ]);

        self::assertSame(2, $this->rowsOf($connection, $base->build()->companyId()));
    }

    public function testSameTripleOfAnotherCompanyIsItsOwnRow(): void
    {
        [$connection, $writer] = $this->writer();
        $ours = AdSkuExpenseFactBuilder::anAdSkuExpenseFact()->withCompanyId(Uuid::v7())->withMarketplaceAccountId(Uuid::v7());
        $theirs = AdSkuExpenseFactBuilder::anAdSkuExpenseFact()->withCompanyId(Uuid::v7())->withMarketplaceAccountId(Uuid::v7());

        // Изоляция арендаторов (ADR-005, обязательное покрытие): та же
        // тройка кампании, дня и SKU у другой компании не перезаписывает
        // нашу — компания входит в ключ.
        $writer->upsertAll([$ours->build()]);
        $writer->upsertAll([$theirs
            ->withAmount(Money::ofMinor(-1, 'RUB'))
            ->withSourceReceivedAt(new \DateTimeImmutable('2026-09-30 08:00:00'))
            ->build()]);

        self::assertSame(-119339, $this->row($connection, $ours->build()->companyId(), $ours->build()->sourceRowIdValue())['amount_minor']);
        self::assertSame(1, $this->rowsOf($connection, $ours->build()->companyId()));
        self::assertSame(1, $this->rowsOf($connection, $theirs->build()->companyId()));
    }

    /**
     * @return array{Connection, DoctrineAdSkuExpenseFactWriter}
     */
    private function writer(): array
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return [$connection, new DoctrineAdSkuExpenseFactWriter($connection)];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Connection $connection, Uuid $companyId, string $sourceRowId): array
    {
        $row = $connection->fetchAssociative(
            'SELECT amount_minor, currency, raw_document_id::text AS raw_document_id, source_received_at, first_loaded_at, last_updated_at FROM ad_sku_expense_fact WHERE company_id = ? AND source_row_id = ?',
            [$companyId->toRfc4122(), $sourceRowId],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function rowsOf(Connection $connection, Uuid $companyId): int
    {
        $count = $connection->fetchOne('SELECT COUNT(*) FROM ad_sku_expense_fact WHERE company_id = ?', [$companyId->toRfc4122()]);
        self::assertTrue(\is_int($count) || \is_string($count));

        return (int) $count;
    }
}
