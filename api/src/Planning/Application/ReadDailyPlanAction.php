<?php

declare(strict_types=1);

namespace App\Planning\Application;

use App\Planning\Domain\DailyPlanMutationOutcome;
use App\Planning\Infrastructure\Query\DailyPlansQuery;

final readonly class ReadDailyPlanAction
{
    public function __construct(private DailyPlanAccess $access, private DailyPlansQuery $query)
    {
    }

    public function __invoke(string $companyId, string $marketplaceAccountId, string $marketplaceSku, \DateTimeImmutable $from, \DateTimeImmutable $to): DailyPlanReadResult
    {
        $scope = $this->access->check($companyId, $marketplaceAccountId, $marketplaceSku);
        if (DailyPlanMutationOutcome::Saved !== $scope) {
            return new DailyPlanReadResult($scope, []);
        }
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);
        if ($to < $from || (int) $from->diff($to)->format('%a') > 89) {
            throw new \InvalidArgumentException('period_invalid');
        }
        /** @var list<array{business_date: string, quantity: int|string|null, version: int|string}> $rows */
        $rows = $this->query->build($companyId, $marketplaceAccountId, $marketplaceSku, $from, $to)
            ->executeQuery()->fetchAllAssociative();
        $items = array_map(static function (array $row): DailyPlanItem {
            $item = DailyPlansQuery::mapRow($row);

            return new DailyPlanItem($item->businessDate, $item->quantity, $item->version);
        }, $rows);

        return new DailyPlanReadResult(DailyPlanMutationOutcome::Saved, $items);
    }
}
