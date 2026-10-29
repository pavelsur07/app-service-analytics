<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

/** One invocation, including repeated verification reads and all candidate observations. */
final class NetQualityBudget
{
    public readonly int $deadline;
    private readonly int $allocation;
    private int $reads = 0;
    private int $bytes = 0;
    private int $candidates = 0;
    private int $spool = 0;

    public function __construct(?int $deadline = null, private readonly int $maxReadBytes = 536870912, private readonly int $maxCandidates = 20000, private readonly int $maxSpoolBytes = 136314880)
    {
        if ($maxReadBytes < 1 || $maxReadBytes > 536870912 || $maxCandidates < 1 || $maxCandidates > 20000 || $maxSpoolBytes < 1 || $maxSpoolBytes > 136314880) {
            throw new \InvalidArgumentException('Evaluation limits may only be tightened.');
        }
        $this->deadline = min($deadline ?? \PHP_INT_MAX, (int) (hrtime(true) + 120000000000));
        $this->allocation = memory_get_usage(true);
    }

    public function guard(): void
    {
        if (hrtime(true) >= $this->deadline || memory_get_peak_usage(true) > 100663296 || memory_get_usage(true) + 8388608 > 100663296 || memory_get_usage(true) - $this->allocation + 8388608 > 50331648) {
            throw new \OverflowException('Quality evaluation resource budget exceeded.');
        }
    }

    public function readBytes(int $bytes): void
    {
        $this->guard();
        if ($bytes < 1 || $bytes > $this->maxReadBytes - $this->bytes) {
            throw new \OverflowException('Quality artifact read-byte limit exceeded.');
        }
        $this->bytes += $bytes;
        ++$this->reads;
    }

    public function candidate(): void
    {
        $this->guard();
        if (++$this->candidates > $this->maxCandidates) {
            throw new \OverflowException('Quality candidate population limit exceeded.');
        }
    }

    public function spoolBytes(int $delta): void
    {
        if ($delta < -$this->spool || $delta > $this->maxSpoolBytes - $this->spool) {
            throw new \OverflowException('Shared quality spool limit exceeded.');
        }
        $this->spool += $delta;
    }

    /** @return array{reads: int, readBytes: int, candidates: int} */
    public function summary(): array
    {
        return ['reads' => $this->reads, 'readBytes' => $this->bytes, 'candidates' => $this->candidates];
    }
}
