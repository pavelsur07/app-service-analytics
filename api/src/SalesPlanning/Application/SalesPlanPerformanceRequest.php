<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\SalesPlanning\Infrastructure\Query\SalesPlanGrouping;

final readonly class SalesPlanPerformanceRequest
{
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public SalesPlanGrouping $groupBy = SalesPlanGrouping::Day,
        public ?string $cursor = null,
        public int $limit = 20,
    ) {
        $start = new \DateTimeImmutable($from->format('Y-m-d'), new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable($to->format('Y-m-d'), new \DateTimeZone('UTC'));
        $maxDays = SalesPlanGrouping::Day === $groupBy ? 31 : 184;
        if ($limit < 1 || $limit > 50 || $start > $end || $start->diff($end)->days >= $maxDays
            || (null !== $cursor && ('' === $cursor || \strlen($cursor) > 2048))) {
            throw new \InvalidArgumentException('Invalid performance range, cursor or limit.');
        }
    }
}
