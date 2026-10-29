<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

final readonly class NetQualityDecisionInput
{
    /** @param array<string, NetQualityGroupResult> $groups
     * @param list<string> $currencies
     * @param list<string> $violations */
    public function __construct(public array $groups, public array $currencies, public array $violations = [])
    {
    }
}
