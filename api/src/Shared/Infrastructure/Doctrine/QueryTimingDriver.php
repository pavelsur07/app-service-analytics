<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\QueryTimingRecorder;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class QueryTimingDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $driver, private readonly QueryTimingRecorder $telemetry)
    {
        parent::__construct($driver);
    }

    public function connect(#[\SensitiveParameter] array $params): Connection
    {
        return new QueryTimingConnection(parent::connect($params), $this->telemetry);
    }
}
