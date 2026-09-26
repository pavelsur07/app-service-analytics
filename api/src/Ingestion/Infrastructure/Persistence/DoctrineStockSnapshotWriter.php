<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\StockSnapshotFact;
use App\Ingestion\Domain\StockSnapshotRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Замена снимка дня (ADR-034, CLAUDE.md §6 — снимочный факт). DBAL,
 * не ORM. Порядок прогонов держит отметка дня: вставка без конфликта
 * (уникальный ключ, без «найти, и если нет — записать», §4), затем
 * блокировка строки до конца транзакции. Два прогона одного дня
 * выстраиваются в очередь на ней, и побеждает более поздний по началу.
 *
 * Удаляются только строки того же дня того же подключения — производные
 * от raw, который остаётся; снимок восстановим по raw_document_ids отметки.
 */
final readonly class DoctrineStockSnapshotWriter implements StockSnapshotRepository
{
    private const int CHUNK_SIZE = 500;

    public function __construct(private Connection $connection)
    {
    }

    public function replaceDay(
        string $companyId,
        Uuid $marketplaceAccountId,
        \DateTimeImmutable $snapshotDate,
        \DateTimeImmutable $startedAt,
        array $requestedSkus,
        array $rawDocumentIds,
        iterable $facts,
    ): bool {
        $accountId = $marketplaceAccountId->toRfc4122();
        $day = $snapshotDate->format('Y-m-d');
        $key = [$companyId, $accountId, $day];
        $started = $startedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return $this->connection->transactional(function (Connection $connection) use ($key, $started, $requestedSkus, $rawDocumentIds, $facts, $companyId, $accountId, $day): bool {
            $connection->executeStatement(
                "INSERT INTO stock_snapshot_run (company_id, marketplace_account_id, snapshot_date, requested_skus, raw_document_ids, row_count)
                 VALUES (?, ?, ?, '[]', '[]', 0)
                 ON CONFLICT (company_id, marketplace_account_id, snapshot_date) DO NOTHING",
                $key,
            );
            $current = $connection->fetchOne(
                'SELECT started_at FROM stock_snapshot_run
                 WHERE company_id = ? AND marketplace_account_id = ? AND snapshot_date = ?
                 FOR UPDATE',
                $key,
            );
            // Строки timestamp(0) в формате Y-m-d H:i:s сравниваются как время.
            if (\is_string($current) && $current >= $started) {
                return false;
            }

            $connection->executeStatement(
                'DELETE FROM stock_snapshot_fact WHERE company_id = ? AND marketplace_account_id = ? AND snapshot_date = ?',
                $key,
            );
            $rows = 0;
            $chunk = [];
            foreach ($facts as $fact) {
                if (
                    $fact->companyId()->toRfc4122() !== $companyId
                    || $fact->marketplaceAccountId()->toRfc4122() !== $accountId
                    || $fact->snapshotDate()->format('Y-m-d') !== $day
                ) {
                    // Исключение откатывает транзакцию: чужая строка не
                    // попадает никуда, день остаётся прежним.
                    throw new \InvalidArgumentException('Stock snapshot fact belongs to another company, account or day.');
                }
                $chunk[] = $fact;
                if (\count($chunk) >= self::CHUNK_SIZE) {
                    $this->insertChunk($connection, $chunk);
                    $rows += \count($chunk);
                    $chunk = [];
                }
            }
            $this->insertChunk($connection, $chunk);
            $rows += \count($chunk);

            $connection->executeStatement(
                'UPDATE stock_snapshot_run
                 SET started_at = ?, first_started_at = COALESCE(first_started_at, ?),
                     requested_skus = ?::jsonb, raw_document_ids = ?::jsonb, row_count = ?
                 WHERE company_id = ? AND marketplace_account_id = ? AND snapshot_date = ?',
                [
                    $started,
                    $started,
                    json_encode(array_values($requestedSkus), \JSON_THROW_ON_ERROR),
                    json_encode(array_map(static fn (Uuid $id): string => $id->toRfc4122(), $rawDocumentIds), \JSON_THROW_ON_ERROR),
                    $rows,
                    ...$key,
                ],
            );

            return true;
        });
    }

    /**
     * @param list<StockSnapshotFact> $facts
     */
    private function insertChunk(Connection $connection, array $facts): void
    {
        if ([] === $facts) {
            return;
        }

        $columns = ['company_id', 'marketplace_account_id', 'snapshot_date', 'source_row_id', 'marketplace_sku',
            'warehouse_id', 'warehouse_name', 'cluster_id', 'cluster_name', 'available', 'transit', 'requested',
            'return_from_customer', 'return_to_seller', 'defect', 'other', 'ads_cluster', 'idc_cluster',
            'turnover_grade_cluster', 'raw_document_id'];
        $rows = [];
        $params = [];
        foreach ($facts as $fact) {
            $quantities = $fact->quantities();
            $rows[] = '('.implode(', ', array_fill(0, \count($columns), '?')).')';
            array_push(
                $params,
                $fact->companyId()->toRfc4122(),
                $fact->marketplaceAccountId()->toRfc4122(),
                $fact->snapshotDate()->format('Y-m-d'),
                $fact->sourceRowId(),
                $fact->marketplaceSku(),
                $fact->warehouseId(),
                $fact->warehouseName(),
                $fact->clusterId(),
                $fact->clusterName(),
                $quantities->available,
                $quantities->transit,
                $quantities->requested,
                $quantities->returnFromCustomer,
                $quantities->returnToSeller,
                $quantities->defect,
                $quantities->other,
                $fact->adsCluster(),
                $fact->idcCluster(),
                $fact->turnoverGradeCluster(),
                $fact->rawDocumentId()->toRfc4122(),
            );
        }

        $connection->executeStatement(
            'INSERT INTO stock_snapshot_fact ('.implode(', ', $columns).') VALUES '.implode(', ', $rows),
            $params,
        );
    }
}
