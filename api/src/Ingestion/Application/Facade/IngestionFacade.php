<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

use App\Ingestion\Infrastructure\Query\ListingSnapshotCriteria;
use App\Ingestion\Infrastructure\Query\ListingSnapshotsQuery;

/**
 * Единственный вход в Ingestion снаружи (CLAUDE.md, «Модули»).
 * Появился вместе с экраном СПП: до него в модуль никто не входил,
 * и заводить Facade заранее было незачем.
 *
 * Метод здесь один, и это правильный размер. Facade растёт по запросу
 * потребителя, а не вперёд: пустой контракт «на будущее» превращается
 * в место, куда складывают всё подряд, и граница исчезает первой.
 */
final class IngestionFacade
{
    public function __construct(
        private readonly ListingSnapshotsQuery $snapshots,
    ) {
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
