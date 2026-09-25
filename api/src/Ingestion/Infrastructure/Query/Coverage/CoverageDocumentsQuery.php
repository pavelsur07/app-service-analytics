<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Coverage;

use App\Ingestion\Domain\Coverage\CoverageDocument;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Выгрузки кабинета для отчёта о полноте данных: по одной строке на
 * (raw-тип, `period`) с последним моментом получения. Company-scoped —
 * `company_id` первым в условии и в индексе raw-слоя (CLAUDE.md §1).
 *
 * Строк не больше, чем типов × дней окна (месяц плюс самый длинный
 * диапазон); потолок — страховка от неожиданного роста, а не пагинация.
 */
final readonly class CoverageDocumentsQuery
{
    public const int MAX_RESULTS = 5_000;

    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<string> $reportTypes
     */
    public function build(string $companyId, string $marketplaceAccountId, array $reportTypes, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('report_type', 'period', 'MAX(received_at) AS last_received_at')
            ->from('marketplace_raw_document')
            ->where('company_id = :companyId')
            ->andWhere('marketplace_account_id = :accountId')
            ->andWhere('report_type IN (:reportTypes)')
            ->andWhere('period BETWEEN :from AND :to')
            ->groupBy('report_type', 'period')
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
    public static function mapRow(array $row): CoverageDocument
    {
        $moscow = new \DateTimeZone(self::TIMEZONE);
        $period = \DateTimeImmutable::createFromFormat('!Y-m-d', self::string($row['period']), $moscow);
        if (false === $period) {
            throw new \UnexpectedValueException('Raw document period is not a date.');
        }

        return new CoverageDocument(
            reportType: self::string($row['report_type']),
            period: $period,
            // received_at хранится в UTC; день получения нужен московский.
            lastReceivedAt: (new \DateTimeImmutable(self::string($row['last_received_at']), new \DateTimeZone('UTC')))->setTimezone($moscow),
        );
    }

    private static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string value in a coverage row.');
        }

        return $value;
    }
}
