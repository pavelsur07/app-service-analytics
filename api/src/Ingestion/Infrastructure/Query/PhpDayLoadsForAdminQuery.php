<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Ingestion\Domain\MarketplaceReportType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Загрузка PHP по дням для сравнения с ozon-fetcher на экране «Покрытие GO»:
 * по типу отчёта и дню — число кабинетов с успешной отметкой загрузки дня
 * (ADR-038).
 *
 * Межарендаторное чтение системного контура (CLAUDE.md §1, ADR-018):
 * экран сравнивает сервис, у которого компании нет, с загрузкой PHP
 * по кабинетам allowlist сервиса — какой бы компании они ни принадлежали.
 * Наружу уходят только счётчики: ни компаний, ни кабинетов в результате
 * нет. Deptrac держит класс в узком слое IngestionAdminCoverageQuery,
 * доступном одному GetServiceCoverageController.
 *
 * Индекс: ведущего столбца `company_id` у запроса нет по построению
 * (§1, «индекс следует за запросом»). Отбор — по кабинетам allowlist
 * и окну не длиннее 93 дней; таблица отметок мала (кабинет × тип × день),
 * отдельный индекс заводится, только если план на проде окажется плохим.
 */
final readonly class PhpDayLoadsForAdminQuery
{
    /**
     * Пара «тип отчёта PHP → ресурс сервиса» — те же выгрузки, что
     * сравнивает bin/ozon-fetcher-observe.sh. У остальных ресурсов
     * сервиса пары в PHP нет.
     */
    public const array RESOURCE_BY_REPORT_TYPE = [
        MarketplaceReportType::OzonPostingFboList => 'ozon.orders.fbo',
        MarketplaceReportType::OzonAccrualByDay => 'ozon.finance.accrual-by-day',
        MarketplaceReportType::OzonAdExpense => 'ozon.ads.expense',
        MarketplaceReportType::OzonAdDaily => 'ozon.ads.daily',
    ];

    /** Окно экрана не длиннее 93 дней — по строке на тип и день. */
    public const int MAX_DAYS = 93;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param non-empty-list<string> $accountIds
     */
    public function build(array $accountIds, string $from, string $to): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('report_type', 'period', 'count(DISTINCT marketplace_account_id) AS cabinets')
            ->from('marketplace_day_load')
            ->where('marketplace_account_id IN (:accounts)')
            ->andWhere('report_type IN (:reportTypes)')
            ->andWhere('period BETWEEN :from AND :to')
            // День загружен, если прогон хоть раз записал все страницы:
            // отказ позже сохраняет время прошлого успеха (ADR-038), и день
            // для сравнения покрытия остаётся загруженным.
            ->andWhere('completed_at IS NOT NULL')
            ->setParameter('accounts', $accountIds, ArrayParameterType::STRING)
            ->setParameter('reportTypes', array_keys(self::RESOURCE_BY_REPORT_TYPE), ArrayParameterType::STRING)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('report_type')
            ->addGroupBy('period')
            ->orderBy('report_type')
            ->addOrderBy('period')
            ->setMaxResults(\count(self::RESOURCE_BY_REPORT_TYPE) * self::MAX_DAYS);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): PhpDayLoadRow
    {
        $reportType = $row['report_type'] ?? null;
        $period = $row['period'] ?? null;
        $cabinets = $row['cabinets'] ?? null;
        if (!\is_string($reportType) || !\is_string($period) || !is_numeric($cabinets)) {
            throw new \UnexpectedValueException('Unexpected day load row.');
        }

        return new PhpDayLoadRow(
            resourceType: self::RESOURCE_BY_REPORT_TYPE[$reportType] ?? throw new \UnexpectedValueException('Unexpected report type.'),
            periodKey: $period,
            cabinets: (int) $cabinets,
        );
    }
}
