<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Response;

final readonly class ResearchAvailabilityResponse
{
    public function __construct(public bool $enabled)
    {
    }
}
