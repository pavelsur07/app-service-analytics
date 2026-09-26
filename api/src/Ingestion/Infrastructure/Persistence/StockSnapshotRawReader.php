<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\MarketplaceReportType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Чтение raw-пачек прогона снимка остатков (ADR-034) для записи дня.
 *
 * Строки всех документов — одним запросом по списку id (CLAUDE.md §6),
 * до открытия транзакции замены дня; тела из объектного хранилища (ADR-024)
 * — по одному, лениво, под блокировкой отметки дня только это чтение.
 *
 * Ограничение, принятое сознательно: колонка body входит в тот же запрос
 * (RawDocumentBody::COLUMNS), и если тела лежат в базе — аварийный режим
 * RAW_BODY_STORE=database (ADR-025), — они приходят все сразу, и память
 * растёт с числом пачек прогона. Читать их из базы по одному значило бы
 * запросы к своей БД в цикле (§6). Документы остатков появились после
 * переноса сырья в S3, и в штатном режиме в памяти одна пачка; аварийный
 * режим временный, а каталоги на этой стадии — десятки SKU.
 * company_id — первым условием, ключ объекта пересобирается из строки,
 * найденной company-scoped запросом (CLAUDE.md §1, RawDocumentBody).
 */
final readonly class StockSnapshotRawReader
{
    public function __construct(
        private Connection $connection,
        private RawDocumentBody $rawDocumentBody,
    ) {
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<array<string, mixed>> строки в порядке $ids
     */
    public function rows(string $companyId, Uuid $marketplaceAccountId, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $idValues = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids);

        $rows = $this->connection->createQueryBuilder()
            ->select('id', RawDocumentBody::COLUMNS)
            ->from('marketplace_raw_document')
            ->where('company_id = :companyId')
            ->andWhere('marketplace_account_id = :accountId')
            ->andWhere('report_type = :reportType')
            ->andWhere('id IN (:ids)')
            ->setParameter('companyId', $companyId)
            ->setParameter('accountId', $marketplaceAccountId->toRfc4122())
            ->setParameter('reportType', MarketplaceReportType::OzonAnalyticsStocks)
            ->setParameter('ids', $idValues, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $byId = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (!\is_string($id)) {
                throw new \UnexpectedValueException('Raw document row must carry its id.');
            }
            $byId[$id] = $row;
        }

        $ordered = [];
        foreach ($idValues as $id) {
            $row = $byId[$id] ?? null;
            if (null === $row) {
                // Документ прогона пропал между записью и заменой дня —
                // неполный снимок записывать нельзя.
                throw new \UnexpectedValueException("Stock raw document {$id} disappeared before the day was replaced.");
            }
            $ordered[] = $row;
        }

        return $ordered;
    }

    /**
     * @param array<string, mixed> $row строка из rows()
     */
    public function body(string $companyId, array $row): string
    {
        return $this->rawDocumentBody->read($companyId, $row);
    }
}
