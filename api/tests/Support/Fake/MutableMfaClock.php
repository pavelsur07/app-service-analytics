<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use Psr\Clock\ClockInterface;

final class MutableMfaClock implements ClockInterface
{
    private \DateTimeImmutable $at;

    public function __construct()
    {
        $this->at = new \DateTimeImmutable();
    }

    public function now(): \DateTimeImmutable
    {
        return $this->at;
    }

    public function advance(int $seconds): void
    {
        $this->at = $this->at->modify(\sprintf('+%d seconds', $seconds));
    }
}
