<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Ingestion\Infrastructure\Query\ListingCostsQuery;
use App\Ingestion\Infrastructure\Query\UnitEconomicsCursor;

/**
 * Список карточек для экрана ввода себестоимости.
 *
 * Порядок задаёт запрос — по выручке за период. Здесь только сборка
 * ответа и подсчёт покрытия: сколько карточек уже с ценой из скольких
 * всего. Это число нужно экрану прямо, а не как украшение: «задано
 * у 8 из 62» — единственный честный ответ на вопрос «почему прибыль
 * не сходится».
 */
final readonly class ListListingCostsAction
{
    public function __construct(
        private ListingCostsQuery $query,
    ) {
    }

    public function __invoke(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $on,
        int $limit,
        ?UnitEconomicsCursor $cursor,
    ): ListingCostsPage {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->query->build($companyId, $from, $to, $on, $limit, $cursor)
            ->executeQuery()
            ->fetchAllAssociative();

        $listings = array_map(ListingCostsQuery::mapRow(...), $rows);

        // +1 строка запрошена ради ответа на «есть ли ещё» — в ответ
        // она не идёт (§5: COUNT(*) на факт-таблицах не выполняется).
        $hasMore = \count($listings) > $limit;
        $listings = \array_slice($listings, 0, $limit);

        $coverage = $this->query->coverage($companyId, $on)->executeQuery()->fetchAssociative();

        $last = $listings[\count($listings) - 1] ?? null;

        return new ListingCostsPage(
            listings: $listings,
            listingCount: self::count($coverage, 'listings'),
            pricedCount: self::count($coverage, 'priced'),
            nextCursor: $hasMore && null !== $last
                ? (new UnitEconomicsCursor($last->revenueMinor, $last->marketplaceSku))->toString()
                : null,
        );
    }

    /**
     * @param array<string, mixed>|false $coverage
     */
    private static function count(array|false $coverage, string $column): int
    {
        if (false === $coverage) {
            // Запрос с агрегатами без GROUP BY всегда отдаёт строку;
            // её отсутствие означало бы, что запрос перестал быть тем,
            // чем задуман, — молчаливый ноль это бы скрыл.
            throw new \UnexpectedValueException('Coverage query returned no row.');
        }

        $value = $coverage[$column] ?? null;
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException("Expected an integer in coverage column {$column}.");
    }
}
