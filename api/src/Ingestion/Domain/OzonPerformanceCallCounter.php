<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/** Operational aggregate of application HTTP calls, without tenant data. */
interface OzonPerformanceCallCounter
{
    public function recordAcrossAccounts(string $operation): void;
}
