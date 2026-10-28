<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/** Provenance задаёт источник вызова, никогда не transport/clock/raw hash. */
enum FactCaptureOrigin: string
{
    case Unknown = 'unknown';
    case LivePolling = 'live_polling';
    case HistoricalBackfill = 'historical_backfill';
    case RawReplay = 'raw_replay';
}
