<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Coverage;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Domain\Coverage\DataCoverage;
use App\Ingestion\Domain\Coverage\DataCoverageCalculator;
use App\Ingestion\Domain\Coverage\DataCoverageSource;
use App\Ingestion\Infrastructure\Query\Coverage\CoverageDocumentsQuery;

/**
 * Отчёт о полноте данных кабинета за месяц: какие эндпоинты Ozon за какие
 * дни загружены. Только чтение того, что уже лежит в raw-слое и в очереди
 * `failed`, — ни одного запроса к площадке.
 *
 * Кабинет ищется в подключениях компании через Facade: чужой или
 * несуществующий — `null`, для вызывающего это 404 (CLAUDE.md §1).
 * Строки рекламы — только у кабинета с подключённым рекламным ключом.
 */
final readonly class BuildDataCoverageAction
{
    public function __construct(
        private IdentityFacade $identityFacade,
        private CoverageDocumentsQuery $documents,
        private FailedLoads $failedLoads,
        private DataCoverageCalculator $calculator,
    ) {
    }

    public function __invoke(string $companyId, string $marketplaceAccountId, \DateTimeImmutable $monthStart, \DateTimeImmutable $today): ?DataCoverage
    {
        $connection = null;
        foreach ($this->identityFacade->listConnections($companyId) as $candidate) {
            // Источники отчёта — эндпоинты Ozon: у кабинета другой площадки
            // строки «нет данных» были бы неправдой.
            if ($candidate->id === $marketplaceAccountId && 'ozon' === $candidate->marketplace) {
                $connection = $candidate;
            }
        }
        if (null === $connection) {
            return null;
        }

        $withAdvertising = null !== $connection->advertisingState;
        $sources = array_values(array_filter(
            DataCoverageSource::all(),
            static fn (DataCoverageSource $source): bool => $withAdvertising || !$source->advertising,
        ));

        $rows = $this->documents->build(
            $companyId,
            $marketplaceAccountId,
            array_map(static fn (DataCoverageSource $source): string => $source->reportType, $sources),
            // Диапазонная выгрузка, начатая до месяца, может заходить в него.
            $monthStart->modify('-'.DataCoverageSource::longestRangeDays().' days'),
            $monthStart->modify('last day of this month'),
        )->executeQuery()->fetchAllAssociative();
        if (\count($rows) > CoverageDocumentsQuery::MAX_RESULTS) {
            throw new \RuntimeException('Raw documents for the coverage report exceed the safety ceiling.');
        }

        $types = array_map(static fn (DataCoverageSource $source): string => $source->reportType, $sources);
        $firstPeriods = CoverageDocumentsQuery::mapFirstPeriods(
            $this->documents->buildFirstPeriods($companyId, $marketplaceAccountId, $types)->executeQuery()->fetchAllAssociative(),
        );

        return $this->calculator->calculate(
            $sources,
            array_map(CoverageDocumentsQuery::mapRow(...), $rows),
            $this->failedLoads->forAccount($companyId, $marketplaceAccountId),
            $monthStart,
            $today,
            $firstPeriods,
        );
    }
}
