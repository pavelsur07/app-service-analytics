<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Ingestion\Domain\MarketplaceReportType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Tenant-scoped keyset чтение immutable raw истории от старой к новой.
 */
final readonly class OzonPostingRawHistoryQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function build(
        string $companyId,
        string $marketplaceAccountId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $toExclusive,
        ?\DateTimeImmutable $cursorReceivedAt,
        ?Uuid $cursorId,
        int $limit,
    ): QueryBuilder {
        $query = $this->connection->createQueryBuilder()
            ->select('id', 'body', 'received_at')
            ->from('marketplace_raw_document')
            ->where('company_id = :companyId')
            ->andWhere('marketplace_account_id = :marketplaceAccountId')
            ->andWhere('report_type = :reportType')
            ->andWhere('received_at >= :from')
            ->andWhere('received_at < :toExclusive')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplaceAccountId', $marketplaceAccountId)
            ->setParameter('reportType', MarketplaceReportType::OzonPostingFboList)
            ->setParameter('from', $from->format('Y-m-d H:i:s'))
            ->setParameter('toExclusive', $toExclusive->format('Y-m-d H:i:s'))
            ->orderBy('received_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($limit);

        if (null !== $cursorReceivedAt && null !== $cursorId) {
            $query->andWhere('(received_at, id) > (:cursorReceivedAt, :cursorId)')
                ->setParameter('cursorReceivedAt', $cursorReceivedAt->format('Y-m-d H:i:s'))
                ->setParameter('cursorId', $cursorId->toRfc4122());
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): OzonPostingRawHistoryRow
    {
        $id = $row['id'] ?? null;
        $body = $row['body'] ?? null;
        $receivedAt = $row['received_at'] ?? null;
        if ((!\is_string($id) && !$id instanceof Uuid) || !\is_string($body) || !\is_string($receivedAt)) {
            throw new \UnexpectedValueException('Malformed Ozon posting raw history row.');
        }

        return new OzonPostingRawHistoryRow(
            id: $id instanceof Uuid ? $id : Uuid::fromString($id),
            body: $body,
            receivedAt: new \DateTimeImmutable($receivedAt),
        );
    }
}
