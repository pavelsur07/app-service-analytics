<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\QueryTimingRecorder;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

final class QueryTimingConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private readonly QueryTimingRecorder $telemetry)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new QueryTimingStatement(parent::prepare($sql), $sql, $this->telemetry);
    }

    public function query(string $sql): Result
    {
        if (!$this->telemetry->enabled()) {
            return parent::query($sql);
        }
        $started = hrtime(true);
        $failed = true;
        try {
            $result = parent::query($sql);
            $failed = false;

            return $result;
        } finally {
            $this->telemetry->recordSql($sql, hrtime(true) - $started, $failed);
        }
    }

    public function exec(string $sql): int|string
    {
        if (!$this->telemetry->enabled()) {
            return parent::exec($sql);
        }
        $started = hrtime(true);
        $failed = true;
        try {
            $result = parent::exec($sql);
            $failed = false;

            return $result;
        } finally {
            $this->telemetry->recordSql($sql, hrtime(true) - $started, $failed);
        }
    }
}
