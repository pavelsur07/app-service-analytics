<?php

declare(strict_types=1);

namespace App\Advertising\Domain;

interface ObservationPolicyRepository
{
    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function transactional(callable $operation): mixed;

    public function add(ObservationPolicy $policy): void;

    public function locked(string $companyId, string $policyId, int $version): ObservationPolicy;
}
