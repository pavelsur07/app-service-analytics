<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Config;

/** Trusted deployment allowlist, closed by default; never a client switch. */
final readonly class NetForecastPublication
{
    public function __construct(private string $publishedModelVersion = '')
    {
    }

    public function allowsModel(string $modelVersion): bool
    {
        return '' !== $modelVersion && $modelVersion === $this->publishedModelVersion;
    }
}
