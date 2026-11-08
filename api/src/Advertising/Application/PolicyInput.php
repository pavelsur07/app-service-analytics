<?php

declare(strict_types=1);

namespace App\Advertising\Application;

use App\Shared\Domain\ValueObject\Money;

final readonly class PolicyInput
{
    public function __construct(public Money $dailyLimit, public string $start, public string $stop, public int $pollSeconds, public bool $enabled)
    {
    }
}
