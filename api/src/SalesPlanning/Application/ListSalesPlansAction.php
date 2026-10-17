<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\SalesPlanning\Infrastructure\Query\SalesPlansQuery;

/**
 * Список планов компании страницей (CLAUDE.md §5): keyset, лимит,
 * без COUNT.
 */
final readonly class ListSalesPlansAction
{
    public function __construct(
        private SalesPlansQuery $plans,
    ) {
    }

    public function __invoke(string $companyId, ?string $marketplaceAccountId, ?string $cursor, int $limit): SalesPlansPage
    {
        /** @var list<array<string, mixed>> $raw */
        $raw = $this->plans->build($companyId, $marketplaceAccountId, $cursor, $limit)->executeQuery()->fetchAllAssociative();

        $items = array_map(SalesPlansQuery::mapRow(...), \array_slice($raw, 0, $limit));
        $last = end($items);

        return new SalesPlansPage($items, \count($raw) > $limit && false !== $last ? $last->id : null);
    }
}
