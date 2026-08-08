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
                .":currency{$i}, :rawDocumentId{$i}, :rowHash{$i}, :firstLoadedAt{$i}, :lastUpdatedAt{$i})";

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
        }

        $sql = <<<SQL
            INSERT INTO sales_fact
                (company_id, marketplace_account_id, source_row_id, business_date, status, marketplace_sku,
                 quantity, amount_minor, commission_amount_minor, currency, raw_document_id, row_hash,
                 first_loaded_at, last_updated_at)
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
