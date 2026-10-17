<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

use App\Ingestion\Infrastructure\Query\Listings\AccountCatalogSkuSearchQuery;
use App\Ingestion\Infrastructure\Query\Listings\AccountCatalogSkusPresentQuery;
use App\Ingestion\Infrastructure\Query\Listings\ListingSnapshotCriteria;
use App\Ingestion\Infrastructure\Query\Listings\ListingSnapshotsQuery;

/**
 * Единственный вход в Ingestion снаружи (CLAUDE.md, «Модули»).
 * Появился вместе с экраном СПП: до него в модуль никто не входил,
 * и заводить Facade заранее было незачем.
 *
 * Потребителей два: экран СПП (ADR-016) и планирование продаж — каталог
 * подключения (ADR-040 п. 4). Facade растёт по запросу потребителя,
 * а не вперёд: пустой контракт «на будущее» превращается в место, куда
 * складывают всё подряд, и граница исчезает первой.
 */
final class IngestionFacade
{
    public const int CatalogPageMaxLimit = 200;

    /**
     * Потолок проверяемого набора — лимит SKU плана (ADR-040 п. 13).
     */
    public const int CatalogPresenceMaxSkus = 1000;

    public function __construct(
        private readonly ListingSnapshotsQuery $snapshots,
        private readonly AccountCatalogSkuSearchQuery $catalogSearch,
        private readonly AccountCatalogSkusPresentQuery $catalogPresence,
    ) {
    }

    /**
     * Поиск SKU в каталоге подключения для выбора в план (ADR-040 п. 4):
     * по артикулу площадки, артикулу продавца или названию, страницей
     * с лимитом (CLAUDE.md §5).
     *
     * $companyId первым параметром (§1); подключение чужой компании
     * даёт пустую страницу.
     */
    public function searchAccountCatalogSkus(
        string $companyId,
        string $marketplaceAccountId,
        string $search,
        ?string $cursor,
        int $limit,
    ): CatalogSkuPage {
        if ($limit < 1 || $limit > self::CatalogPageMaxLimit) {
            throw new \InvalidArgumentException(\sprintf('Лимит страницы каталога — от 1 до %d.', self::CatalogPageMaxLimit));
        }

        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $this->catalogSearch
            ->build($companyId, $marketplaceAccountId, trim($search), $cursor, $limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $hasMore = \count($rawRows) > $limit;
        $items = array_map(
            static function (array $raw): CatalogSku {
                $row = AccountCatalogSkuSearchQuery::mapRow($raw);

                return new CatalogSku($row->marketplaceSku, $row->offerId, $row->name);
            },
            \array_slice($rawRows, 0, $limit),
        );

        $last = end($items);

        return new CatalogSkuPage($items, $hasMore && false !== $last ? $last->marketplaceSku : null);
    }

    /**
     * Какие из переданных SKU есть в каталоге подключения (ADR-040 п. 4):
     * весь набор одним запросом — без SKU за запросом (§6) и без обхода
     * страниц. Дубли во входе схлопываются; пустой вход — пустой ответ
     * без запроса.
     *
     * $companyId первым параметром (§1); подключение чужой компании
     * не находит ничего.
     *
     * @param list<string> $skus
     *
     * @return list<string> найденные SKU, по возрастанию
     */
    public function presentAccountCatalogSkus(string $companyId, string $marketplaceAccountId, array $skus): array
    {
        $skus = array_values(array_unique($skus));
        if ([] === $skus) {
            return [];
        }

        if (\count($skus) > self::CatalogPresenceMaxSkus) {
            throw new \InvalidArgumentException(\sprintf('За раз проверяется не больше %d SKU.', self::CatalogPresenceMaxSkus));
        }

        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $this->catalogPresence
            ->build($companyId, $marketplaceAccountId, $skus)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $raw): string => AccountCatalogSkusPresentQuery::mapRow($raw)->marketplaceSku,
            $rawRows,
        );
    }

    /**
     * Карточки на указанные моменты: по одному моменту на артикул,
     * потому что у каждого наблюдения он свой (ADR-015). Кабинет
     * в запросе обязателен — см. `ListingSnapshotRequest`.
     *
     * Выполнение и разбор здесь, а не в Query: тот отдаёт QueryBuilder
     * (CLAUDE.md §5), а Facade — то место, где строка выборки
     * превращается в межмодульный DTO.
     *
     * Список целиком, а не артикул за вызов: запрос на строку был бы
     * запросом в цикле (CLAUDE.md §6). Внутри — один SQL на весь экран.
     *
     * $companyId первым параметром (CLAUDE.md §1).
     *
     * @param list<ListingSnapshotRequest> $requests
     *
     * @return array<string, ListingSnapshot> по артикулу
     */
    public function listingSnapshotsAt(string $companyId, array $requests): array
    {
        if ([] === $requests) {
            return [];
        }

        // Перевод в тип запроса — здесь: Infrastructure вверх
        // не смотрит, и свой тип у неё свой (ListingSnapshotCriteria).
        $criteria = array_map(
            static fn (ListingSnapshotRequest $request): ListingSnapshotCriteria => new ListingSnapshotCriteria(
                marketplaceSku: $request->marketplaceSku,
                marketplaceAccountId: $request->marketplaceAccountId,
                at: $request->at,
            ),
            $requests,
        );

        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $this->snapshots->build($companyId, $criteria)->executeQuery()->fetchAllAssociative();

        $snapshots = [];
        foreach (array_map(ListingSnapshotsQuery::mapRow(...), $rawRows) as $row) {
            $snapshots[$row->marketplaceSku] = new ListingSnapshot(
                marketplaceSku: $row->marketplaceSku,
                name: $row->name,
                price: $row->price(),
            );
        }

        return $snapshots;
    }
}
