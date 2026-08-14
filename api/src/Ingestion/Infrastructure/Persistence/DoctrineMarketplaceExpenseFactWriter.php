<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\MarketplaceExpenseFact;
use App\Ingestion\Domain\MarketplaceExpenseFactRepository;
use Doctrine\DBAL\Connection;

/**
 * DBAL, не ORM (CLAUDE.md §6). Устроен как DoctrineSalesFactWriter,
 * и намеренно: обе таблицы — факты одного конвейера, и расхождение
 * в приёмах записи означало бы, что при следующей правке ADR-006
 * поправят одну и забудут другую.
 *
 * ON CONFLICT DO UPDATE с `WHERE row_hash IS DISTINCT FROM` — апдейт
 * только при реально изменившейся сумме; first_loaded_at в SET не входит
 * вовсе.
 */
final readonly class DoctrineMarketplaceExpenseFactWriter implements MarketplaceExpenseFactRepository
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
     * @param list<MarketplaceExpenseFact> $facts
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
                .":marketplaceSku{$i}, :feeTypeId{$i}, :unitNumber{$i}, :amountMinor{$i}, "
                .":currency{$i}, :rawDocumentId{$i}, :rowHash{$i}, :firstLoadedAt{$i}, :lastUpdatedAt{$i})";

            $params["companyId{$i}"] = $fact->companyId()->toRfc4122();
            $params["marketplaceAccountId{$i}"] = $fact->marketplaceAccountId()->toRfc4122();
            $params["sourceRowId{$i}"] = $fact->sourceRowIdValue();
            $params["businessDate{$i}"] = $fact->businessDate()->format('Y-m-d');
            $params["marketplaceSku{$i}"] = $fact->marketplaceSku();
            $params["feeTypeId{$i}"] = $fact->feeTypeId();
            $params["unitNumber{$i}"] = $fact->unitNumber();
            $params["amountMinor{$i}"] = $fact->amount()->minorAmount();
            $params["currency{$i}"] = $fact->amount()->currency();
            $params["rawDocumentId{$i}"] = $fact->rawDocumentId()->toRfc4122();
            $params["rowHash{$i}"] = $fact->rowHash();
            $params["firstLoadedAt{$i}"] = $fact->firstLoadedAt()->format('Y-m-d H:i:sP');
            $params["lastUpdatedAt{$i}"] = $fact->lastUpdatedAt()->format('Y-m-d H:i:sP');
        }

        $sql = <<<SQL
            INSERT INTO marketplace_expense_fact
                (company_id, marketplace_account_id, source_row_id, business_date, marketplace_sku,
                 fee_type_id, unit_number, amount_minor, currency, raw_document_id, row_hash,
                 first_loaded_at, last_updated_at)
            VALUES {$this->joinValues($valuesSql)}
            ON CONFLICT (company_id, marketplace_account_id, source_row_id)
            DO UPDATE SET
                business_date = EXCLUDED.business_date,
                amount_minor = EXCLUDED.amount_minor,
                currency = EXCLUDED.currency,
                raw_document_id = EXCLUDED.raw_document_id,
                row_hash = EXCLUDED.row_hash,
                last_updated_at = EXCLUDED.last_updated_at
            WHERE marketplace_expense_fact.row_hash IS DISTINCT FROM EXCLUDED.row_hash
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
