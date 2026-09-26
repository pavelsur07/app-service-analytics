<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Localization;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** Сводка отчёта «Локализация» за период: одна строка. */
final readonly class LocalizationSummaryQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        $source = 'WITH '.LocalizationSql::linesCte().' SELECT '.LocalizationSql::metricsSelect().' FROM lines';

        return $this->connection->createQueryBuilder()
            ->select('summary.*')
            ->from('('.$source.')', 'summary')
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'));
    }
}
