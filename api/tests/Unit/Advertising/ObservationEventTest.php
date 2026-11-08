<?php

declare(strict_types=1);

namespace App\Tests\Unit\Advertising;

use App\Advertising\Domain\ObservationEvent;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\ObservationPolicyBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObservationEventTest extends TestCase
{
    /** @return iterable<string, array{int, string}> */
    public static function invalidMoney(): iterable
    {
        yield 'currency' => [100, 'USD'];
        yield 'negative' => [-1, 'RUB'];
        yield 'outside JS integer range' => [9007199254740992, 'RUB'];
    }

    #[DataProvider('invalidMoney')]
    public function testRejectsInvalidJournalMoney(int $minor, string $currency): void
    {
        $p = ObservationPolicyBuilder::aPolicy()->build();
        $this->expectException(\InvalidArgumentException::class);
        new ObservationEvent($p->companyId(), $p->id(), $p->accountId(), $p->campaignId(), 1, 'key', 'observation', null, 'blocked', 'primary_unavailable', ['day' => '2026-11-08'], new \DateTimeImmutable('2026-11-08'), spend: Money::ofMinor($minor, $currency));
    }
}
