<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

final class NetTrainingReadException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
