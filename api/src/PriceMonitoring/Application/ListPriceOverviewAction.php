<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Application;

use App\Ingestion\Application\Facade\IngestionFacade;
use App\Ingestion\Application\Facade\ListingSnapshot;
use App\PriceMonitoring\Infrastructure\Query\TrackedSkuOverviewQuery;
use App\PriceMonitoring\Infrastructure\Query\TrackedSkuOverviewRow;

/**
 * Экран СПП: отслеживаемые артикулы, две цены и разница (ADR-014).
 *
 * Данные лежат в двух модулях, и соединяются они здесь, а не в SQL:
 * граница, пересечённая внутри запроса, Deptrac не видна (ADR-016).
 * Цена кабинета приходит одним вызовом Facade на весь экран — запрос
 * на строку был бы запросом в цикле (CLAUDE.md §6).
 *
 * **Цена кабинета берётся на момент наблюдения, а не сегодняшняя.**
 * Ради этого история и заводилась (ADR-015): сравнение вчерашней
 * витрины с сегодняшней ценой дало бы число, выглядящее как настоящее.
 */
final class ListPriceOverviewAction
{
    public function __construct(
        private readonly TrackedSkuOverviewQuery $trackedSkus,
        private readonly IngestionFacade $ingestion,
    ) {
    }

    /**
     * @return list<PriceOverviewRow>
     */
    public function __invoke(string $companyId, int $limit): array
    {
        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $this->trackedSkus->build($companyId, $limit)->executeQuery()->fetchAllAssociative();
        $rows = array_map(TrackedSkuOverviewQuery::mapRow(...), $rawRows);

        $snapshots = $this->ingestion->listingSnapshotsAt($companyId, $this->momentsBySku($rows));

        return array_map(
            fn (TrackedSkuOverviewRow $row): PriceOverviewRow => $this->compose($row, $snapshots[$row->marketplaceSku] ?? null),
            $rows,
        );
    }

    /**
     * Артикул без наблюдений в запрос к каталогу не попадает: момента
     * у него нет, а спрашивать «цена на сейчас» значило бы подставить
     * величину, к которой не с чем сравнивать.
     *
     * @param list<TrackedSkuOverviewRow> $rows
     *
     * @return array<string, \DateTimeImmutable>
     */
    private function momentsBySku(array $rows): array
    {
        $moments = [];
        foreach ($rows as $row) {
            if (null !== $row->observedAt) {
                $moments[$row->marketplaceSku] = $row->observedAt;
            }
        }

        return $moments;
    }

    private function compose(TrackedSkuOverviewRow $row, ?ListingSnapshot $snapshot): PriceOverviewRow
    {
        $displayed = $row->displayedPrice();
        $seller = $snapshot?->price;

        return new PriceOverviewRow(
            marketplaceSku: $row->marketplaceSku,
            name: $snapshot?->name,
            sellerPrice: $seller,
            displayedPrice: $displayed,
            // Обе величины или ничего: посчитать разницу с одной
            // означало бы выдать половину за целое.
            coInvestment: (null !== $seller && null !== $displayed) ? $seller->minus($displayed) : null,
            observedAt: $row->observedAt,
        );
    }
}
