<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

use App\Shared\Domain\ValueObject\Money;

final readonly class PerformanceMoneyResponse
{
    public function __construct(public int $minor, public string $currency)
    {
    }

    public static function of(?Money $money): ?self
    {
        return null === $money ? null : new self($money->minorAmount(), $money->currency());
    }
}
