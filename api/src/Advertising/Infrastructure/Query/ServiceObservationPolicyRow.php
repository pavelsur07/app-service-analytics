<?php

declare(strict_types=1);

namespace App\Advertising\Infrastructure\Query;

use App\Shared\Domain\ValueObject\Money;

final readonly class ServiceObservationPolicyRow
{
    public function __construct(
        public string $id,
        public string $companyId,
        public string $accountId,
        public string $campaignId,
        public int $version,
        public bool $enabled,
        public Money $dailyLimit,
        public string $start,
        public string $stop,
        public int $pollSeconds,
    ) {
    }
}
