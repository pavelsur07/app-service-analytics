<?php

declare(strict_types=1);

namespace App\Tests\Unit\Advertising;

use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\ObservationStateBuilder;
use PHPUnit\Framework\TestCase;

final class ObservationStateTest extends TestCase
{
    public function testStateNeverRelabelsAnotherCurrencyAsRubles(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ObservationStateBuilder::aState()->withSpend(Money::ofMinor(103, 'USD'))->build();
    }
}
