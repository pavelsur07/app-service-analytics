<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

final readonly class NetQualitySelection
{
    /** @param iterable<NetQualityObservation> $observations
     * @return \Generator<int, NetQualityObservation> */
    public function select(iterable $observations, NetQualityBudget $budget): \Generator
    {
        $spool = new NetQualitySelectionSpool($budget);
        $selected = [];
        $dates = [];
        foreach ($observations as $observation) {
            $budget->candidate();
            $case = $observation->caseKey();
            $order = hash('sha256', $observation->companyId.'|'.$observation->accountId.'|'.$observation->orderNumber);
            if (isset($dates[$order]) && $dates[$order] !== $observation->businessDate) {
                throw new \DomainException('One order cannot cross calibration/holdout dates.');
            }
            $dates[$order] = $observation->businessDate;
            $groups = str_starts_with($observation->group, 'order') ? [$observation->group, 'overall'] : [$observation->group];
            $position = null;
            foreach ($groups as $group) {
                $key = $case.':'.$group;
                $rank = [$observation->readAtUtc, $observation->inputRef->requestId];
                if (!isset($selected[$key]) || $rank < [$selected[$key][2], $selected[$key][3]]) {
                    $position ??= $spool->append($observation);
                    $selected[$key] = [$position[0], $position[1], $rank[0], $rank[1], $group];
                }
            }
        }
        ksort($selected, \SORT_STRING);
        foreach ($selected as [$offset, $length, , , $group]) {
            $budget->guard();
            yield $spool->read($offset, $length)->withGroup($group);
        }
    }
}
