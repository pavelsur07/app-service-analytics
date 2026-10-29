<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\QueryTimingRecorder;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

#[AsMiddleware]
final readonly class QueryTimingMiddleware implements Middleware
{
    public function __construct(private QueryTimingRecorder $telemetry)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new QueryTimingDriver($driver, $this->telemetry);
    }
}
