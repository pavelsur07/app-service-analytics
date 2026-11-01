<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use Psr\Clock\ClockInterface;

final class ResearchClock implements ClockInterface
{
    private \DateTimeImmutable $at;

    public function __construct()
    {
        $this->at = new \DateTimeImmutable('2026-04-15T09:00:00Z');
    }

    public function now(): \DateTimeImmutable
    {
        return $this->at;
    }

    public function freeze(string $at): void
    {
        $this->at = new \DateTimeImmutable($at);
    }
}
