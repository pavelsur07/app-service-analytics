<?php

declare(strict_types=1);

namespace App\Tests\Support\Ingestion;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;

/** @phpstan-import-type WrapperParameterTypeArray from Connection */
final class NetQualityProbeConnection extends Connection
{
    public bool $forbidSourceQueries = false;
    public int $anchorReads = 0;

    /** @param list<mixed>|array<string, mixed> $params
     * @phpstan-param WrapperParameterTypeArray $types */
    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        if ($this->forbidSourceQueries && 1 === preg_match('/\b(?:sales_fact(?:_revision)?|marketplace_return_fact(?:_revision)?|marketplace_posting_status|marketplace_day_load(?:_receipt)?)\b/i', $sql)) {
            throw new \LogicException('Scoring attempted to read live source tables.');
        }
        if (1 === preg_match('/\bFROM\s+net_forecast_artifact\b/i', $sql)) {
            ++$this->anchorReads;
        }

        return parent::executeQuery($sql, $params, $types, $qcp);
    }
}
