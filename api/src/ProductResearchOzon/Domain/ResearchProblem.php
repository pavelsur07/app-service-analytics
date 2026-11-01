<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

final class ResearchProblem extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
