<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\MarketplacePostingStatus;
use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use Doctrine\DBAL\Connection;

/**
 * Append-only status history (ADR-019): PK/raw закрывает retry, а сравнение
 * с последним наблюдением не раздувает историю неизменившимися опросами.
 */
final readonly class DoctrineMarketplacePostingStatusWriter implements MarketplacePostingStatusRepository
{
    private const int CHUNK_SIZE = 500;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function recordChanged(string $companyId, array $statuses): int
    {
        foreach ($statuses as $status) {
            if ($status->companyId()->toRfc4122() !== $companyId) {
                throw new \InvalidArgumentException('Posting status batch must contain only the requested company.');
            }
        }

        $inserted = 0;
        foreach (array_chunk($statuses, self::CHUNK_SIZE) as $chunk) {
            $inserted += $this->recordChunk($companyId, $chunk);
        }

        return $inserted;
    }

    /**
     * @param list<MarketplacePostingStatus> $statuses
     */
    private function recordChunk(string $companyId, array $statuses): int
    {
        if ([] === $statuses) {
            return 0;
        }

        $values = [];
        $params = ['companyId' => $companyId];
        foreach ($statuses as $index => $status) {
            $values[] = "(:companyId, :accountId{$index}, :postingNumber{$index}, :rawDocumentId{$index}, :orderNumber{$index}, :status{$index}, :substatus{$index}, :cancelReasonId{$index}, :observedAt{$index})";
            $params["accountId{$index}"] = $status->marketplaceAccountId()->toRfc4122();
            $params["postingNumber{$index}"] = $status->postingNumber();
            $params["rawDocumentId{$index}"] = $status->rawDocumentId()->toRfc4122();
            $params["orderNumber{$index}"] = $status->orderNumber();
            $params["status{$index}"] = $status->status();
            $params["substatus{$index}"] = $status->substatus();
            $params["cancelReasonId{$index}"] = $status->cancelReasonId();
            $params["observedAt{$index}"] = $status->observedAt()->format('Y-m-d H:i:s');
        }

        $rows = implode(', ', $values);
        $sql = <<<SQL
            INSERT INTO marketplace_posting_status
                (company_id, marketplace_account_id, posting_number, raw_document_id,
                 order_number, status, substatus, cancel_reason_id, observed_at)
            SELECT v.company_id::uuid, v.account_id::uuid, v.posting_number,
                   v.raw_document_id::uuid, v.order_number, v.status, v.substatus,
                   v.cancel_reason_id::bigint, v.observed_at::timestamp(0)
            FROM (VALUES {$rows}) AS v(
                company_id, account_id, posting_number, raw_document_id,
                order_number, status, substatus, cancel_reason_id, observed_at
            )
            WHERE NOT EXISTS (
                SELECT 1
                FROM LATERAL (
                    SELECT previous.status, previous.substatus, previous.cancel_reason_id
                    FROM marketplace_posting_status previous
                    WHERE previous.company_id = v.company_id::uuid
                      AND previous.marketplace_account_id = v.account_id::uuid
                      AND previous.posting_number = v.posting_number
                      AND (previous.observed_at, previous.raw_document_id)
                          < (v.observed_at::timestamp(0), v.raw_document_id::uuid)
                    ORDER BY previous.observed_at DESC, previous.raw_document_id DESC
                    LIMIT 1
                ) previous
                WHERE previous.status = v.status
                  AND previous.substatus IS NOT DISTINCT FROM v.substatus
                  AND previous.cancel_reason_id IS NOT DISTINCT FROM v.cancel_reason_id::bigint
            )
            ON CONFLICT (company_id, marketplace_account_id, posting_number, raw_document_id)
            DO NOTHING
            SQL;

        return (int) $this->connection->executeStatement($sql, $params);
    }
}
