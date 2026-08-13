<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\MarketplaceListing;
use App\Ingestion\Domain\MarketplaceListingRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * DBAL, не ORM: каталог наполняется синхронизацией, не человеком
 * (CLAUDE.md §6). Тот же приём, что у DoctrineSalesFactWriter —
 * INSERT ... ON CONFLICT по естественному ключу, один запрос на чанк,
 * не запрос на строку.
 *
 * Отличие от фактов: здесь ещё и удаление. Площадка отдаёт весь список,
 * поэтому строки, не пришедшие в эту синхронизацию, из каталога уходят.
 * Признак — `last_seen_at` старше момента синхронизации: он проставляется
 * всем пришедшим, значит всё, что осталось со старой отметкой, площадка
 * больше не отдаёт.
 *
 * Всё в одной транзакции: между «залили новое» и «удалили старое»
 * каталог не должен быть виден ни пустым, ни задвоенным — оверлей
 * в этот момент спросил бы «мой ли товар» и получил неверный ответ.
 */
final readonly class DoctrineMarketplaceListingWriter implements MarketplaceListingRepository
{
    private const int CHUNK_SIZE = 500;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function replaceForAccount(
        string $companyId,
        Uuid $marketplaceAccountId,
        array $listings,
        \DateTimeImmutable $syncedAt,
    ): void {
        $this->connection->transactional(function () use ($companyId, $marketplaceAccountId, $listings, $syncedAt): void {
            foreach (array_chunk($listings, self::CHUNK_SIZE) as $chunk) {
                $this->upsertChunk($chunk);
            }

            $this->deleteVanished($companyId, $marketplaceAccountId, $syncedAt);
        });
    }

    /**
     * @param list<MarketplaceListing> $listings
     */
    private function upsertChunk(array $listings): void
    {
        if ([] === $listings) {
            return;
        }

        $valuesSql = [];
        $params = [];
        foreach ($listings as $i => $listing) {
            $valuesSql[] = "(:companyId{$i}, :marketplaceAccountId{$i}, :marketplaceSku{$i}, :firstSeenAt{$i}, :lastSeenAt{$i})";

            $params["companyId{$i}"] = $listing->companyId()->toRfc4122();
            $params["marketplaceAccountId{$i}"] = $listing->marketplaceAccountId()->toRfc4122();
            $params["marketplaceSku{$i}"] = $listing->marketplaceSku();
            $params["firstSeenAt{$i}"] = $listing->firstSeenAt()->format('Y-m-d H:i:sP');
            $params["lastSeenAt{$i}"] = $listing->lastSeenAt()->format('Y-m-d H:i:sP');
        }

        // first_seen_at в SET нет намеренно: товар, который мы уже видели,
        // не становится новым от того, что синхронизация прошла снова.
        $sql = <<<SQL
            INSERT INTO marketplace_listing
                (company_id, marketplace_account_id, marketplace_sku, first_seen_at, last_seen_at)
            VALUES {$this->joinValues($valuesSql)}
            ON CONFLICT (company_id, marketplace_account_id, marketplace_sku)
            DO UPDATE SET last_seen_at = EXCLUDED.last_seen_at
            SQL;

        $this->connection->executeStatement($sql, $params);
    }

    private function deleteVanished(
        string $companyId,
        Uuid $marketplaceAccountId,
        \DateTimeImmutable $syncedAt,
    ): void {
        // companyId в условии, хотя marketplace_account_id уже однозначен:
        // изоляция арендаторов держится на SQL, а не на том, что
        // вызывающий передал согласованную пару (CLAUDE.md §1).
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM marketplace_listing
                WHERE company_id = :companyId
                  AND marketplace_account_id = :marketplaceAccountId
                  AND last_seen_at < :syncedAt
                SQL,
            [
                'companyId' => $companyId,
                'marketplaceAccountId' => $marketplaceAccountId->toRfc4122(),
                'syncedAt' => $syncedAt->format('Y-m-d H:i:sP'),
            ],
        );
    }

    /**
     * @param list<string> $valuesSql
     */
    private function joinValues(array $valuesSql): string
    {
        return implode(', ', $valuesSql);
    }
}
