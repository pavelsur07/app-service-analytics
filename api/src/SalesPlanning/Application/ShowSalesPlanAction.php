<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\SalesPlanning\Infrastructure\Query\SalesPlanGrouping;
use App\SalesPlanning\Infrastructure\Query\SalesPlanSkusQuery;
use App\SalesPlanning\Infrastructure\Query\SalesPlansQuery;

/**
 * План для экрана (ADR-040 п. 3): шапка, страница SKU с итогами
 * и корзины выбранной группировки — три запроса на страницу, не запрос
 * на SKU (CLAUDE.md §6).
 */
final readonly class ShowSalesPlanAction
{
    public function __construct(
        private SalesPlansQuery $plans,
        private SalesPlanSkusQuery $skus,
    ) {
    }

    /**
     * @return SalesPlanView|null null — у компании нет такого плана
     */
    public function __invoke(string $companyId, string $planId, SalesPlanGrouping $grouping, ?string $cursor, int $limit): ?SalesPlanView
    {
        $header = $this->plans->withPlanId($companyId, $planId)->executeQuery()->fetchAssociative();
        if (false === $header) {
            return null;
        }

        /** @var list<array<string, mixed>> $raw */
        $raw = $this->skus->page($companyId, $planId, $cursor, $limit)->executeQuery()->fetchAllAssociative();
        $skus = array_map(SalesPlanSkusQuery::mapSku(...), \array_slice($raw, 0, $limit));
        $last = end($skus);

        $buckets = [];
        $pageSkus = array_map(static fn ($sku): string => $sku->marketplaceSku, $skus);
        if ([] !== $pageSkus && SalesPlanGrouping::Period !== $grouping) {
            /** @var list<array<string, mixed>> $rawBuckets */
            $rawBuckets = $this->skus->buckets($companyId, $planId, $pageSkus, $grouping)->executeQuery()->fetchAllAssociative();
            foreach (array_map(SalesPlanSkusQuery::mapBucket(...), $rawBuckets) as $bucket) {
                $buckets[$bucket->marketplaceSku][] = $bucket;
            }
        }

        return new SalesPlanView(
            plan: SalesPlansQuery::mapRow($header),
            skus: $skus,
            buckets: $buckets,
            nextCursor: \count($raw) > $limit && false !== $last ? $last->marketplaceSku : null,
        );
    }
}
