<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

use App\Ingestion\Application\NetForecast\NetForecastSeriesRequest;
use App\Ingestion\Application\NetForecast\NetForwardArtifactReader;
use App\Ingestion\Application\NetForecast\NetForwardCaptureRequest;
use App\Ingestion\Application\NetForecast\NetForwardPlanContext;
use App\Ingestion\Application\NetForecast\NetTrainingSnapshot;
use App\Ingestion\Application\NetForecast\PrepareNetForecastCaptureAction;
use App\Ingestion\Application\NetForecast\PublishNetForecastCaptureAction;
use App\Ingestion\Application\NetForecast\ReadNetForecastAction;
use App\Ingestion\Application\NetForecast\VerifiedNetForwardArtifact;
use App\Ingestion\Domain\NetForecastArtifactRepository;
use App\Ingestion\Domain\NetForwardArtifactKind;
use App\Ingestion\Domain\ValueObject\ObservedReturnCurve;
use App\Ingestion\Infrastructure\Config\NetForecastPublication;
use App\Ingestion\Infrastructure\Query\Buyout\BuyoutMaturityQuery;
use App\Ingestion\Infrastructure\Query\Listings\AccountCatalogSkuSearchQuery;
use App\Ingestion\Infrastructure\Query\Listings\AccountCatalogSkusPresentQuery;
use App\Ingestion\Infrastructure\Query\Listings\ListingSnapshotCriteria;
use App\Ingestion\Infrastructure\Query\Listings\ListingSnapshotsQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetRequest;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortMetricsRow;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortSeriesQuery;
use Psr\Clock\ClockInterface;

/**
 * Единственный вход в Ingestion снаружи (CLAUDE.md, «Модули»).
 * Появился вместе с экраном СПП: до него в модуль никто не входил,
 * и заводить Facade заранее было незачем.
 *
 * Потребителей два: экран СПП (ADR-016) и планирование продаж — каталог
 * подключения (ADR-040 п. 4). Facade растёт по запросу потребителя,
 * а не вперёд: пустой контракт «на будущее» превращается в место, куда
 * складывают всё подряд, и граница исчезает первой.
 *
 * @phpstan-type NetPoints array<string, array<string, array{period: SalesCohortNetValues, cumulative: SalesCohortNetValues}>>
 */
final class IngestionFacade
{
    public const int CatalogPageMaxLimit = 200;

    /**
     * Потолок проверяемого набора — лимит SKU плана (ADR-040 п. 13).
     */
    public const int CatalogPresenceMaxSkus = 1000;

    public function __construct(
        private readonly ListingSnapshotsQuery $snapshots,
        private readonly AccountCatalogSkuSearchQuery $catalogSearch,
        private readonly AccountCatalogSkusPresentQuery $catalogPresence,
        private readonly SalesCohortSeriesQuery $cohortSeries,
        private readonly BuyoutMaturityQuery $cohortMaturity,
        private readonly ClockInterface $clock,
        private readonly NetForecastPublication $netPublication,
        private readonly ReadNetForecastAction $netForecast,
        private readonly NetTrainingSnapshot $netSnapshot,
        private readonly SalesCohortNetMapper $netMapper = new SalesCohortNetMapper(),
        private readonly ?PrepareNetForecastCaptureAction $prepareForward = null,
        private readonly ?PublishNetForecastCaptureAction $publishForward = null,
        private readonly ?NetForwardArtifactReader $forwardReader = null,
        private readonly ?NetForecastArtifactRepository $forwardRepository = null,
    ) {
    }

    public function findForwardCapture(string $companyId, string $accountId, ForwardCaptureRequest $request): ?ForwardArtifactRef
    {
        $repository = $this->forwardRepository ?? throw new \LogicException('Forward capture unavailable.');
        $reader = $this->forwardReader ?? throw new \LogicException('Forward capture unavailable.');
        $reader->assertNoTransaction();
        if (null === $repository->find($companyId, $accountId, NetForwardArtifactKind::Input, $request->requestId)) {
            return null;
        }

        return self::forwardRef($reader->read($companyId, $accountId, NetForwardArtifactKind::Input, $request->requestId, requestHash: $request->hash()));
    }

    public function prepareForwardCapture(string $companyId, string $accountId, ForwardCaptureRequest $request, ForwardPlanContext $plan, int $deadlineNs): PreparedForwardCapture
    {
        $prepare = $this->prepareForward ?? throw new \LogicException('Forward capture unavailable.');
        $publish = $this->publishForward ?? throw new \LogicException('Forward capture unavailable.');
        $prepared = $prepare($companyId, $accountId,
            new NetForwardCaptureRequest($request->requestId, $request->planId, $request->from, $request->to, $request->groupBy),
            new NetForwardPlanContext($plan->planId, $plan->planVersion, $plan->versionNumber, $plan->baselineId, $plan->baselineVersion, $plan->metric, $plan->currency, $plan->accumulationFrom, $plan->marketplaceSkus, $plan->goalRows), $deadlineNs);

        return new PreparedForwardCapture($companyId, $accountId, static fn (): ForwardArtifactRef => self::forwardRef($publish($companyId, $accountId, $prepared)));
    }

    public function publishForwardCapture(string $companyId, string $accountId, PreparedForwardCapture $capture): ForwardArtifactRef
    {
        return $capture->publish($companyId, $accountId);
    }

    private static function forwardRef(VerifiedNetForwardArtifact $artifact): ForwardArtifactRef
    {
        $chunks = $artifact->manifest['chunks'];
        if (!\is_array($chunks)) {
            throw new \UnexpectedValueException('Artifact chunks missing.');
        }

        return new ForwardArtifactRef($artifact->publication->root->kind->value, $artifact->publication->root->requestId, $artifact->publication->root->sha256, $artifact->readAt, \count($chunks));
    }

    /**
     * Поиск SKU в каталоге подключения для выбора в план (ADR-040 п. 4):
     * по артикулу площадки, артикулу продавца или названию, страницей
     * с лимитом (CLAUDE.md §5).
     *
     * $companyId первым параметром (§1); подключение чужой компании
     * даёт пустую страницу.
     */
    public function searchAccountCatalogSkus(
        string $companyId,
        string $marketplaceAccountId,
        string $search,
        ?string $cursor,
        int $limit,
    ): CatalogSkuPage {
        if ($limit < 1 || $limit > self::CatalogPageMaxLimit) {
            throw new \InvalidArgumentException(\sprintf('Лимит страницы каталога — от 1 до %d.', self::CatalogPageMaxLimit));
        }

        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $this->catalogSearch
            ->build($companyId, $marketplaceAccountId, trim($search), $cursor, $limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $hasMore = \count($rawRows) > $limit;
        $items = array_map(
            static function (array $raw): CatalogSku {
                $row = AccountCatalogSkuSearchQuery::mapRow($raw);

                return new CatalogSku($row->marketplaceSku, $row->offerId, $row->name);
            },
            \array_slice($rawRows, 0, $limit),
        );

        $last = end($items);

        return new CatalogSkuPage($items, $hasMore && false !== $last ? $last->marketplaceSku : null);
    }

    /**
     * Какие из переданных SKU есть в каталоге подключения (ADR-040 п. 4):
     * весь набор одним запросом — без SKU за запросом (§6) и без обхода
     * страниц. Дубли во входе схлопываются; пустой вход — пустой ответ
     * без запроса.
     *
     * $companyId первым параметром (§1); подключение чужой компании
     * не находит ничего.
     *
     * @param list<string> $skus
     *
     * @return list<string> найденные SKU, по возрастанию
     */
    public function presentAccountCatalogSkus(string $companyId, string $marketplaceAccountId, array $skus): array
    {
        $skus = array_values(array_unique($skus));
        if ([] === $skus) {
            return [];
        }

        if (\count($skus) > self::CatalogPresenceMaxSkus) {
            throw new \InvalidArgumentException(\sprintf('За раз проверяется не больше %d SKU.', self::CatalogPresenceMaxSkus));
        }

        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $this->catalogPresence
            ->build($companyId, $marketplaceAccountId, $skus)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $raw): string => AccountCatalogSkusPresentQuery::mapRow($raw)->marketplaceSku,
            $rawRows,
        );
    }

    /**
     * Карточки на указанные моменты: по одному моменту на артикул,
     * потому что у каждого наблюдения он свой (ADR-015). Кабинет
     * в запросе обязателен — см. `ListingSnapshotRequest`.
     *
     * Выполнение и разбор здесь, а не в Query: тот отдаёт QueryBuilder
     * (CLAUDE.md §5), а Facade — то место, где строка выборки
     * превращается в межмодульный DTO.
     *
     * Список целиком, а не артикул за вызов: запрос на строку был бы
     * запросом в цикле (CLAUDE.md §6). Внутри — один SQL на весь экран.
     *
     * $companyId первым параметром (CLAUDE.md §1).
     *
     * @param list<ListingSnapshotRequest> $requests
     *
     * @return array<string, ListingSnapshot> по артикулу
     */
    public function listingSnapshotsAt(string $companyId, array $requests): array
    {
        if ([] === $requests) {
            return [];
        }

        // Перевод в тип запроса — здесь: Infrastructure вверх
        // не смотрит, и свой тип у неё свой (ListingSnapshotCriteria).
        $criteria = array_map(
            static fn (ListingSnapshotRequest $request): ListingSnapshotCriteria => new ListingSnapshotCriteria(
                marketplaceSku: $request->marketplaceSku,
                marketplaceAccountId: $request->marketplaceAccountId,
                at: $request->at,
            ),
            $requests,
        );

        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $this->snapshots->build($companyId, $criteria)->executeQuery()->fetchAllAssociative();

        $snapshots = [];
        foreach (array_map(ListingSnapshotsQuery::mapRow(...), $rawRows) as $row) {
            $snapshots[$row->marketplaceSku] = new ListingSnapshot(
                marketplaceSku: $row->marketplaceSku,
                name: $row->name,
                price: $row->price(),
            );
        }

        return $snapshots;
    }

    /** Current mutable order cohorts; no historical snapshot or return-tail promise. */
    public function salesCohortMetrics(string $companyId, string $accountId, SalesCohortRequest $request): SalesCohortBatch
    {
        if ([] === $request->marketplaceSkus || !$this->netPublication->allowsModel(ObservedReturnCurve::MODEL_VERSION)) {
            $calculatedAt = $this->clock->now();

            return [] === $request->marketplaceSkus ? new SalesCohortBatch([], $calculatedAt, $request)
                : $this->readGrossCohorts($companyId, $accountId, $request, $calculatedAt, SalesCohortNetMetadata::pending());
        }

        return $this->netSnapshot->read(function () use ($companyId, $accountId, $request): SalesCohortBatch {
            $net = ($this->netForecast)($companyId, $accountId,
                new NetTargetRequest($request->marketplaceSkus, $request->from, $request->to, $request->accumulationFrom),
                new NetForecastSeriesRequest($request->marketplaceSkus, $request->from, $request->to, $request->accumulationFrom, $request->groupBy->value));
            $metadata = new SalesCohortNetMetadata(readAt: $net->readAt, epochId: $net->context?->epoch->id,
                captureVersion: $net->context?->epoch->captureVersion, validationStatus: 'release_approved', assessmentStatus: 'assessed');
            $calculatedAt = $net->readAt ?? $this->clock->now();
            $reason = (null === $net->series ? null : $net->series->unavailableReason) ?? $net->unavailableReason ?? 'source_history_unavailable';
            $moneyReason = $net->moneyUnavailableReason ?? $reason;
            try {
                $projection = $this->netMapper->map($net, $request);
                $points = $projection->points;
            } catch (\OverflowException) {
                $points = [];
                $projection = null;
                $reason = $moneyReason = 'input_budget_exceeded';
            } catch (\UnexpectedValueException) {
                $points = [];
                $projection = null;
                $reason = $moneyReason = 'source_history_unavailable';
            }
            unset($net);

            return $this->readGrossCohorts($companyId, $accountId, $request, $calculatedAt, $metadata, $points, $reason, $moneyReason, $projection);
        });
    }

    /** @param NetPoints $netPoints */
    private function readGrossCohorts(string $companyId, string $accountId, SalesCohortRequest $request, \DateTimeImmutable $calculatedAt, SalesCohortNetMetadata $metadata, array $netPoints = [], string $netReason = 'validation_pending', string $moneyReason = 'validation_pending', ?SalesCohortNetProjection $projection = null): SalesCohortBatch
    {
        if ([] === $request->marketplaceSkus) {
            throw new \LogicException('Gross cohort reader requires a nonempty SKU page.');
        }
        $query = $this->cohortMaturity->build($companyId, $accountId, $calculatedAt);
        $started = null === $projection?->budget ? null : hrtime(true);
        $maturity = $query->executeQuery()->fetchAssociative();
        $sqlElapsed = null === $started ? 0 : hrtime(true) - $started;
        if (false === $maturity) {
            throw new \UnexpectedValueException('Missing sales cohort maturity aggregate.');
        }
        $p95 = BuyoutMaturityQuery::mapRow($maturity)->p95Seconds;
        $query = $this->cohortSeries->build(
            $companyId, $accountId, $request->marketplaceSkus,
            $request->from, $request->to, $request->accumulationFrom,
            $request->groupBy->value, $request->valuationCurrency, $calculatedAt, $p95,
        );
        $started = null === $projection?->budget ? null : hrtime(true);
        $rows = $query->executeQuery()->fetchAllAssociative();
        $sqlElapsed += null === $started ? 0 : hrtime(true) - $started;
        $skus = [];
        foreach ($rows as $raw) {
            $row = SalesCohortSeriesQuery::mapRow($raw);
            $points = [];
            foreach ($row->points as $point) {
                $points[] = new SalesCohortPoint(
                    $point->bucketFrom, $point->bucketTo, $point->periodCoverage, $point->cumulativeCoverage,
                    self::cohortComponents($point->period, $netPoints[$row->marketplaceSku][$point->bucketFrom.'|'.$point->bucketTo]['period'] ?? null, $metadata, $netReason, $moneyReason),
                    self::cohortComponents($point->cumulative, $netPoints[$row->marketplaceSku][$point->bucketFrom.'|'.$point->bucketTo]['cumulative'] ?? null, $metadata, $netReason, $moneyReason),
                );
            }
            $skus[] = new SalesCohortSku($row->marketplaceSku, $points);
        }

        // Account-wide coverage is identical for all requested SKU. The
        // last cumulative point already contains the SQL range assessment.
        $coveragePoints = $skus[0]->points ?? [];
        $lastPoint = [] === $coveragePoints ? null : $coveragePoints[array_key_last($coveragePoints)];

        return new SalesCohortBatch($skus, $calculatedAt, $request, ordersCoverage: null === $lastPoint ? 'not_assessed' : $lastPoint->cumulativeCoverage, netForecast: $metadata,
            forecastBudget: null === $projection?->budget ? null : new SalesCohortForecastBudget(
                $projection->budget->computeDeadlineNs, $projection->budget->allocationBaselineBytes, $projection->budget->outputBytesUsed,
                $projection->budget->allocationLimitBytes, $projection->budget->peakLimitBytes, $projection->budget->outputLimitBytes, $sqlElapsed),
            goalForecastMethodVersion: $projection?->goalForecastMethodVersion, goalForecastProvenance: $projection->goalForecastProvenance ?? []);
    }

    private static function cohortComponents(?SalesCohortMetricsRow $row, ?SalesCohortNetValues $net, SalesCohortNetMetadata $metadata, string $netReason, string $moneyReason): ?SalesCohortComponents
    {
        if (null === $row) {
            return null;
        }

        return new SalesCohortComponents(
            $row->rawOrderedQuantity, $row->preHandoverCancelledQuantity, $row->effectiveOrderedQuantity,
            $row->observedNetSalesQuantity, $row->observedRevenue, $row->inFlightQuantity,
            $row->buyoutResolvedQuantity, $row->unestimatedQuantity, $row->unknownOutcomeQuantity,
            $row->knownProjectedBuyoutQuantity, $row->knownProjectedEligibleQuantity,
            $row->maturityStatus, $row->inFlightRateBps, $row->knownBuyoutRateBps,
            $row->actualBuyoutRateBps, $row->currentProjectedBuyoutQuantity,
            $row->currentProjectedBuyoutRateBps, $row->unestimatedRateBps,
            $net?->quantity, null === $net ? $netReason : $net->reason,
            $net?->revenue, null === $net ? $moneyReason : $net->moneyReason,
            (null === $net ? null : $net->quality) ?? ('not_assessed' === $metadata->assessmentStatus ? SalesCohortNetQuality::pending()
                : new SalesCohortNetQuality($netReason, $moneyReason, [], [], $metadata->validationStatus, $metadata->assessmentStatus)),
            $net?->goalForecast,
        );
    }
}
