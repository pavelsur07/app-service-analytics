<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Config;

final readonly class ResearchRollout
{
    /** @var list<string> */
    private array $companies;

    public function __construct(string $researchAllowedCompanyIds)
    {
        $companies = '' === $researchAllowedCompanyIds || \strlen($researchAllowedCompanyIds) > 40000 ? [] : explode(',', $researchAllowedCompanyIds);
        $valid = \count($companies) <= 1000 && \count(array_unique($companies)) === \count($companies);
        foreach ($companies as $company) {
            if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $company)) {
                $valid = false;
            }
        }
        $this->companies = $valid ? $companies : [];
    }

    public function allows(string $companyId): bool
    {
        return \in_array($companyId, $this->companies, true);
    }
}
