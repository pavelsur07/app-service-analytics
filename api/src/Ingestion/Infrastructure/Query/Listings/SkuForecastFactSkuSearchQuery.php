<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Listings;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** Catalog matches include unsold products; sales history retains removed SKUs. */
final readonly class SkuForecastFactSkuSearchQuery
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 200;

    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, string $search, ?string $cursor, int $limit): QueryBuilder
    {
        $where = '';
        if ('' !== $search) {
            $where = "AND (marketplace_sku ILIKE :pattern ESCAPE '!' OR name ILIKE :pattern ESCAPE '!' OR offer_id ILIKE :pattern ESCAPE '!')";
        }
        $cursorWhere = null === $cursor ? '' : 'AND marketplace_sku > :cursor';
        $substringHistorySearch = 1 === preg_match('/[\p{L}\p{N}]{3}/u', $search);
        $factCondition = $substringHistorySearch
            ? "marketplace_sku ILIKE :pattern ESCAPE '!'"
            : 'marketplace_sku = :exactSku';
        $factSearch = '' === $search ? '' : <<<SQL
            UNION
            SELECT marketplace_sku FROM sales_fact
            WHERE company_id = :companyId AND $factCondition
                  $cursorWhere
            SQL;

        $matched = <<<SQL
            (
                SELECT DISTINCT marketplace_sku FROM marketplace_listing
                WHERE company_id = :companyId $cursorWhere $where
                $factSearch
            )
            SQL;

        $qb = $this->connection->createQueryBuilder()
            ->select('matched.marketplace_sku', 'catalog.name', 'catalog.offer_id')
            ->from($matched, 'matched')
            ->leftJoin('matched', 'LATERAL (
                SELECT name, offer_id FROM marketplace_listing
                WHERE company_id = :companyId AND marketplace_sku = matched.marketplace_sku
                ORDER BY marketplace_account_id LIMIT 1
            )', 'catalog', 'TRUE')
            ->setParameter('companyId', $companyId)
            ->orderBy('matched.marketplace_sku', 'ASC')
            ->setMaxResults($limit + 1);

        if ('' !== $search) {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $qb->setParameter('pattern', '%'.$escaped.'%');
            if (!$substringHistorySearch) {
                $qb->setParameter('exactSku', $search);
            }
        }
        if (null !== $cursor) {
            $qb->setParameter('cursor', $cursor);
        }

        return $qb;
    }

    /** @param array<string, mixed> $row */
    public static function mapRow(array $row): SkuForecastFactSkuRow
    {
        $sku = $row['marketplace_sku'];
        $name = $row['name'];
        $offerId = $row['offer_id'];
        if ((!\is_string($sku) && !\is_int($sku)) || (null !== $name && !\is_string($name)) || (null !== $offerId && !\is_string($offerId))) {
            throw new \UnexpectedValueException('Invalid SKU search row.');
        }

        return new SkuForecastFactSkuRow((string) $sku, $name, $offerId);
    }
}
