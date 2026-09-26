<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DeliverySpeed;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Сводка: показатели по наблюдавшимся вживую отправлениям и число всех
 * отправлений периода — чтобы доля «видели вживую» была видна рядом.
 */
final readonly class DeliverySpeedSummaryQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        $source = 'WITH '.DeliverySpeedSql::timedCte()
            .' SELECT (SELECT COUNT(*) FROM postings)::bigint AS period_postings, '
            .DeliverySpeedSql::metricsSelect().' FROM timed WHERE live';

        return $this->connection->createQueryBuilder()
            ->select('summary.*')
            ->from('('.$source.')', 'summary')
            ->setParameters(DeliverySpeedSql::parameters($companyId, $from, $to));
    }
}
