<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\SalesFact;
use App\Ingestion\Domain\SalesFactRepository;
use Doctrine\DBAL\Connection;

/**
 * DBAL, не ORM (CLAUDE.md §6: факт-таблицы ORM никогда не пишет).
 * INSERT ... ON CONFLICT DO UPDATE по естественному ключу, апдейт —
 * только если row_hash реально изменился: WHERE ... IS DISTINCT FROM
 * не даёт ON CONFLICT перезаписать first_loaded_at при неизменном
 * контенте (в SET его нет вовсе — колонка не участвует в обновлении).
 *
 * Один SQL-запрос на чанк, не один на строку (CLAUDE.md: «запросов
 * в цикле нет») — VALUES с несколькими кортежами в одном INSERT.
 */
final readonly class DoctrineSalesFactWriter implements SalesFactRepository
{
    private const int CHUNK_SIZE = 500;

    /**
     * Строка после COALESCE совпадает с разобранным историческим фактом
     * во всех полях row_hash (SalesFact::computeRowHash) — значит,
     * EXCLUDED.row_hash и есть хэш этой строки по текущей формуле.
     * Нужен, чтобы расширение формулы хэша (атрибуты доставки, кластеры)
     * не превращалось в мнимую корректировку задним числом: без обновления
     * хэша первая синхронизация переписала бы всё окно с новым
     * last_updated_at, хотя данные площадки не менялись (ADR-006).
     */
    private const string SNAPSHOT_MATCHES_EXCLUDED = <<<'SQL'
        sales_fact.status = EXCLUDED.status
        AND sales_fact.quantity = EXCLUDED.quantity
        AND sales_fact.amount_minor = EXCLUDED.amount_minor
        AND sales_fact.commission_amount_minor = EXCLUDED.commission_amount_minor
        AND COALESCE(sales_fact.posting_number, EXCLUDED.posting_number) IS NOT DISTINCT FROM EXCLUDED.posting_number
        AND COALESCE(sales_fact.order_number, EXCLUDED.order_number) IS NOT DISTINCT FROM EXCLUDED.order_number
        AND COALESCE(sales_fact.warehouse_id, EXCLUDED.warehouse_id) IS NOT DISTINCT FROM EXCLUDED.warehouse_id
        AND COALESCE(sales_fact.warehouse_name, EXCLUDED.warehouse_name) IS NOT DISTINCT FROM EXCLUDED.warehouse_name
        AND COALESCE(sales_fact.delivery_city, EXCLUDED.delivery_city) IS NOT DISTINCT FROM EXCLUDED.delivery_city
        AND COALESCE(sales_fact.cluster_from, EXCLUDED.cluster_from) IS NOT DISTINCT FROM EXCLUDED.cluster_from
        AND COALESCE(sales_fact.cluster_to, EXCLUDED.cluster_to) IS NOT DISTINCT FROM EXCLUDED.cluster_to
        SQL;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function upsertAll(array $facts): void
    {
        foreach (array_chunk($facts, self::CHUNK_SIZE) as $chunk) {
            $this->upsertChunk($chunk);
        }
    }

    public function backfillLinks(string $companyId, array $facts): void
    {
        foreach (array_chunk($facts, self::CHUNK_SIZE) as $chunk) {
            $this->backfillLinksChunk($companyId, $chunk);
        }
    }

    /**
     * @param list<SalesFact> $facts
     */
    private function backfillLinksChunk(string $companyId, array $facts): void
    {
        if ([] === $facts) {
            return;
        }

        $valuesSql = [];
        $params = [];
        foreach ($facts as $i => $fact) {
            if ($fact->companyId()->toRfc4122() !== $companyId) {
                throw new \InvalidArgumentException('Sales fact belongs to another company.');
            }

            $valuesSql[] = "(:companyId{$i}, :marketplaceAccountId{$i}, :sourceRowId{$i}, :businessDate{$i}, "
                .":status{$i}, :marketplaceSku{$i}, :quantity{$i}, :amountMinor{$i}, :commissionAmountMinor{$i}, "
                .":currency{$i}, :rawDocumentId{$i}, :rowHash{$i}, :firstLoadedAt{$i}, :lastUpdatedAt{$i}, "
                .":postingNumber{$i}, :orderNumber{$i}, :warehouseId{$i}, :warehouseName{$i}, :deliveryCity{$i}, "
                .":clusterFrom{$i}, :clusterTo{$i})";

            $params["companyId{$i}"] = $companyId;
            $params["marketplaceAccountId{$i}"] = $fact->marketplaceAccountId()->toRfc4122();
            $params["sourceRowId{$i}"] = $fact->sourceRowId();
            $params["businessDate{$i}"] = $fact->businessDate()->format('Y-m-d');
            $params["status{$i}"] = $fact->status();
            $params["marketplaceSku{$i}"] = $fact->marketplaceSku();
            $params["quantity{$i}"] = $fact->quantity();
            $params["amountMinor{$i}"] = $fact->amount()->minorAmount();
            $params["commissionAmountMinor{$i}"] = $fact->commissionAmount()->minorAmount();
            $params["currency{$i}"] = $fact->amount()->currency();
            $params["rawDocumentId{$i}"] = $fact->rawDocumentId()->toRfc4122();
            $params["rowHash{$i}"] = $fact->rowHash();
            $params["firstLoadedAt{$i}"] = $fact->firstLoadedAt()->format('Y-m-d H:i:sP');
            $params["lastUpdatedAt{$i}"] = $fact->lastUpdatedAt()->format('Y-m-d H:i:sP');
            $params["postingNumber{$i}"] = $fact->postingNumber();
            $params["orderNumber{$i}"] = $fact->orderNumber();
            $params["warehouseId{$i}"] = $fact->warehouseId();
            $params["warehouseName{$i}"] = $fact->warehouseName();
            $params["deliveryCity{$i}"] = $fact->deliveryCity();
            $params["clusterFrom{$i}"] = $fact->clusterFrom();
            $params["clusterTo{$i}"] = $fact->clusterTo();
        }

        $snapshotMatches = self::SNAPSHOT_MATCHES_EXCLUDED;
        $sql = <<<SQL
            INSERT INTO sales_fact
                (company_id, marketplace_account_id, source_row_id, business_date, status, marketplace_sku,
                 quantity, amount_minor, commission_amount_minor, currency, raw_document_id, row_hash,
                 first_loaded_at, last_updated_at, posting_number, order_number,
                 warehouse_id, warehouse_name, delivery_city, cluster_from, cluster_to)
            VALUES {$this->joinValues($valuesSql)}
            ON CONFLICT (company_id, marketplace_account_id, source_row_id)
            DO UPDATE SET
                row_hash = CASE WHEN {$snapshotMatches} THEN EXCLUDED.row_hash ELSE sales_fact.row_hash END,
                posting_number = COALESCE(sales_fact.posting_number, EXCLUDED.posting_number),
                order_number = COALESCE(sales_fact.order_number, EXCLUDED.order_number),
                warehouse_id = COALESCE(sales_fact.warehouse_id, EXCLUDED.warehouse_id),
                warehouse_name = COALESCE(sales_fact.warehouse_name, EXCLUDED.warehouse_name),
                delivery_city = COALESCE(sales_fact.delivery_city, EXCLUDED.delivery_city),
                cluster_from = COALESCE(sales_fact.cluster_from, EXCLUDED.cluster_from),
                cluster_to = COALESCE(sales_fact.cluster_to, EXCLUDED.cluster_to)
            WHERE (sales_fact.posting_number IS NULL AND EXCLUDED.posting_number IS NOT NULL)
               OR (sales_fact.order_number IS NULL AND EXCLUDED.order_number IS NOT NULL)
               OR (sales_fact.warehouse_id IS NULL AND EXCLUDED.warehouse_id IS NOT NULL)
               OR (sales_fact.warehouse_name IS NULL AND EXCLUDED.warehouse_name IS NOT NULL)
               OR (sales_fact.delivery_city IS NULL AND EXCLUDED.delivery_city IS NOT NULL)
               OR (sales_fact.cluster_from IS NULL AND EXCLUDED.cluster_from IS NOT NULL)
               OR (sales_fact.cluster_to IS NULL AND EXCLUDED.cluster_to IS NOT NULL)
               OR (sales_fact.row_hash IS DISTINCT FROM EXCLUDED.row_hash AND {$snapshotMatches})
            SQL;

        $this->connection->executeStatement($sql, $params);
    }

    /**
     * @param list<SalesFact> $facts
     */
    private function upsertChunk(array $facts): void
    {
        if ([] === $facts) {
            return;
        }

        $valuesSql = [];
        $params = [];
        foreach ($facts as $i => $fact) {
            $valuesSql[] = "(:companyId{$i}, :marketplaceAccountId{$i}, :sourceRowId{$i}, :businessDate{$i}, "
                .":status{$i}, :marketplaceSku{$i}, :quantity{$i}, :amountMinor{$i}, :commissionAmountMinor{$i}, "
                .":currency{$i}, :rawDocumentId{$i}, :rowHash{$i}, :firstLoadedAt{$i}, :lastUpdatedAt{$i}, "
                .":postingNumber{$i}, :orderNumber{$i}, :warehouseId{$i}, :warehouseName{$i}, :deliveryCity{$i}, "
                .":clusterFrom{$i}, :clusterTo{$i})";

            $params["companyId{$i}"] = $fact->companyId()->toRfc4122();
            $params["marketplaceAccountId{$i}"] = $fact->marketplaceAccountId()->toRfc4122();
            $params["sourceRowId{$i}"] = $fact->sourceRowId();
            $params["businessDate{$i}"] = $fact->businessDate()->format('Y-m-d');
            $params["status{$i}"] = $fact->status();
            $params["marketplaceSku{$i}"] = $fact->marketplaceSku();
            $params["quantity{$i}"] = $fact->quantity();
            $params["amountMinor{$i}"] = $fact->amount()->minorAmount();
            $params["commissionAmountMinor{$i}"] = $fact->commissionAmount()->minorAmount();
            $params["currency{$i}"] = $fact->amount()->currency();
            $params["rawDocumentId{$i}"] = $fact->rawDocumentId()->toRfc4122();
            $params["rowHash{$i}"] = $fact->rowHash();
            $params["firstLoadedAt{$i}"] = $fact->firstLoadedAt()->format('Y-m-d H:i:sP');
            $params["lastUpdatedAt{$i}"] = $fact->lastUpdatedAt()->format('Y-m-d H:i:sP');
            $params["postingNumber{$i}"] = $fact->postingNumber();
            $params["orderNumber{$i}"] = $fact->orderNumber();
            $params["warehouseId{$i}"] = $fact->warehouseId();
            $params["warehouseName{$i}"] = $fact->warehouseName();
            $params["deliveryCity{$i}"] = $fact->deliveryCity();
            $params["clusterFrom{$i}"] = $fact->clusterFrom();
            $params["clusterTo{$i}"] = $fact->clusterTo();
        }

        $sql = <<<SQL
            INSERT INTO sales_fact
                (company_id, marketplace_account_id, source_row_id, business_date, status, marketplace_sku,
                 quantity, amount_minor, commission_amount_minor, currency, raw_document_id, row_hash,
                 first_loaded_at, last_updated_at, posting_number, order_number,
                 warehouse_id, warehouse_name, delivery_city, cluster_from, cluster_to)
            VALUES {$this->joinValues($valuesSql)}
            ON CONFLICT (company_id, marketplace_account_id, source_row_id)
            DO UPDATE SET
                status = EXCLUDED.status,
                quantity = EXCLUDED.quantity,
                amount_minor = EXCLUDED.amount_minor,
                commission_amount_minor = EXCLUDED.commission_amount_minor,
                currency = EXCLUDED.currency,
                raw_document_id = EXCLUDED.raw_document_id,
                row_hash = EXCLUDED.row_hash,
                last_updated_at = EXCLUDED.last_updated_at
                , posting_number = EXCLUDED.posting_number
                , order_number = EXCLUDED.order_number
                , warehouse_id = EXCLUDED.warehouse_id
                , warehouse_name = EXCLUDED.warehouse_name
                , delivery_city = EXCLUDED.delivery_city
                , cluster_from = EXCLUDED.cluster_from
                , cluster_to = EXCLUDED.cluster_to
            WHERE sales_fact.row_hash IS DISTINCT FROM EXCLUDED.row_hash
            SQL;

        $this->connection->executeStatement($sql, $params);
    }

    /**
     * @param list<string> $valuesSql
     */
    private function joinValues(array $valuesSql): string
    {
        return implode(', ', $valuesSql);
    }
}
