<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Ingestion\Domain\FactCaptureOrigin;
use Symfony\Component\Messenger\Stamp\SerializerStamp;

/** Existing sendable stamp is understood by old consumers and application rollback. */
final class FactCaptureContext
{
    public const string KEY = 'conwix_fact_capture_origin_v1';

    /** @return list<SerializerStamp> */
    public static function stamps(FactCaptureOrigin $origin): array
    {
        return [new SerializerStamp([self::KEY => $origin->value])];
    }
}
