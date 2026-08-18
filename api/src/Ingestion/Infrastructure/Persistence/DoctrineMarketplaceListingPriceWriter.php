<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\MarketplaceListingPrice;
use App\Ingestion\Domain\MarketplaceListingPriceRepository;
use Doctrine\DBAL\Connection;

/**
 * DBAL, не ORM (CLAUDE.md §6: факт-таблицы ORM никогда не пишет).
 *
 * Вся суть — в условии `NOT EXISTS`: строка появляется, только если
 * значение отличается от последнего известного по этому артикулу
 * (ADR-015). Синхронизация идёт каждые полчаса, и без этого условия
 * таблица набирала бы три тысячи одинаковых строк в сутки на компанию.
 *
 * Условие внутри запроса, а не «прочитать последнюю и сравнить»:
 * два параллельных прогона прошли бы такую проверку оба (CLAUDE.md §4).
 * Идемпотентность при этом держит не оно, а уникальный ключ, в котором
 * стоит raw-документ: повтор того же ответа площадки даёт тот же
 * документ (ADR-006 дедуплицирует сырьё по содержимому) и упирается
 * в `ON CONFLICT DO NOTHING`. Условие `NOT EXISTS` отвечает
 * за другое — чтобы неизменившаяся цена не заводила строку каждые
 * полчаса.
 *
 * Одновременность двух прогонов по одному кабинету закрыта выше
 * по стеку, блокировкой в `FetchOzonCatalogHandler`: без неё подвисший
 * старый прогон дописывал бы устаревшую цену после нового.
 *
 * Один запрос на всю выгрузку, не запрос на артикул (§6): значения
 * едут списком в `VALUES`, чанками — как у `DoctrineSalesFactWriter`.
 */
final readonly class DoctrineMarketplaceListingPriceWriter implements MarketplaceListingPriceRepository
{
    private const int CHUNK_SIZE = 500;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function recordChanged(string $companyId, array $prices): void
    {
        foreach (array_chunk($prices, self::CHUNK_SIZE) as $chunk) {
            $this->recordChunk($companyId, $chunk);
        }
    }

    /**
     * @param list<MarketplaceListingPrice> $prices
     */
    private function recordChunk(string $companyId, array $prices): void
    {
        if ([] === $prices) {
            return;
        }

        $values = [];
        $params = ['companyId' => $companyId];
        foreach ($prices as $i => $price) {
            $values[] = "(:companyId, :accountId{$i}, :sku{$i}, :rawDocumentId{$i}, :changedAt{$i}, :price{$i}, :oldPrice{$i}, :currency{$i})";
            $params["rawDocumentId{$i}"] = $price->rawDocumentId()->toRfc4122();
            $params["accountId{$i}"] = $price->marketplaceAccountId()->toRfc4122();
            $params["sku{$i}"] = $price->marketplaceSku();
            $params["changedAt{$i}"] = $price->changedAt()->format('Y-m-d H:i:s');
            $params["price{$i}"] = $price->price()->minorAmount();
            $params["oldPrice{$i}"] = $price->oldPrice()?->minorAmount();
            $params["currency{$i}"] = $price->price()->currency();
        }

        $rows = implode(', ', $values);

        // IS DISTINCT FROM у old_price, а не `<>`: у товара без
        // зачёркнутой цены там NULL, и обычное сравнение вернуло бы
        // NULL вместо «не отличается» — строка заводилась бы на каждой
        // синхронизации.
        $sql = <<<SQL
            INSERT INTO marketplace_listing_price
                (company_id, marketplace_account_id, marketplace_sku, raw_document_id,
                 changed_at, price_minor, old_price_minor, currency)
            SELECT v.company_id::uuid, v.account_id::uuid, v.sku, v.raw_document_id::uuid,
                   v.changed_at::timestamp(0), v.price::bigint, v.old_price::bigint, v.currency
            FROM (VALUES {$rows}) AS v(company_id, account_id, sku, raw_document_id, changed_at, price, old_price, currency)
            WHERE NOT EXISTS (
                SELECT 1
                FROM marketplace_listing_price last
                WHERE last.company_id = v.company_id::uuid
                  AND last.marketplace_account_id = v.account_id::uuid
                  AND last.marketplace_sku = v.sku
                  AND last.changed_at = (
                      SELECT max(previous.changed_at)
                      FROM marketplace_listing_price previous
                      WHERE previous.company_id = last.company_id
                        AND previous.marketplace_account_id = last.marketplace_account_id
                        AND previous.marketplace_sku = last.marketplace_sku
                  )
                  AND last.price_minor = v.price::bigint
                  AND last.old_price_minor IS NOT DISTINCT FROM v.old_price::bigint
                  AND last.currency = v.currency
            )
            ON CONFLICT (company_id, marketplace_account_id, marketplace_sku, raw_document_id) DO NOTHING
            SQL;

        $this->connection->executeStatement($sql, $params);
    }
}
