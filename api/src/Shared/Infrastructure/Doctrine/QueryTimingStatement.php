<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\QueryTimingRecorder;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

final class QueryTimingStatement extends AbstractStatementMiddleware
{
    public function __construct(Statement $statement, private readonly string $sql, private readonly QueryTimingRecorder $telemetry)
    {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        if (!$this->telemetry->enabled()) {
            return parent::execute();
        }
        $started = hrtime(true);
        $failed = true;
        try {
            $result = parent::execute();
            $failed = false;

            return $result;
        } finally {
            $this->telemetry->recordSql($this->sql, hrtime(true) - $started, $failed);
        }
    }
}
