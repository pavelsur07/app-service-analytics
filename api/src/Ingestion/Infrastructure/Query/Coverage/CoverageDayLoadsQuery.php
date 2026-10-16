<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Coverage;

use App\Ingestion\Domain\Coverage\CoverageFailure;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Дни кабинета, последний прогон которых отказал, — по отметкам загрузки
 * (ADR-038). В отличие от очереди `failed`, отметка не пропадает при её
 * очистке и снимается только успешным повтором дня.
 *
 * Company-scoped: `company_id` первым в условии и в первичном ключе
 * таблицы отметок (CLAUDE.md §1). Строк не больше, чем типов × дней
 * месяца; потолок — страховка, а не пагинация.
 */
final readonly class CoverageDayLoadsQuery
{
    public const int MAX_RESULTS = 1_000;

    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<string> $reportTypes
     */
    public function rejected(string $companyId, string $marketplaceAccountId, array $reportTypes, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('report_type', 'period')
            ->from('marketplace_day_load')
            ->where('company_id = :companyId')
            ->andWhere('marketplace_account_id = :accountId')
            ->andWhere('report_type IN (:reportTypes)')
            ->andWhere('period BETWEEN :from AND :to')
            ->andWhere('failed_at IS NOT NULL')
            ->orderBy('report_type')
            ->addOrderBy('period')
            ->setParameter('companyId', $companyId)
            ->setParameter('accountId', $marketplaceAccountId)
            ->setParameter('reportTypes', $reportTypes, ArrayParameterType::STRING)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setMaxResults(self::MAX_RESULTS + 1);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): CoverageFailure
    {
        $reportType = $row['report_type'] ?? null;
        $period = $row['period'] ?? null;
        if (!\is_string($reportType) || !\is_string($period)) {
            throw new \UnexpectedValueException('Expected string values in a day load row.');
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $period, new \DateTimeZone(self::TIMEZONE));
        if (false === $day) {
            throw new \UnexpectedValueException('Day load period is not a date.');
        }

        return new CoverageFailure($reportType, $day, $day);
    }
}
