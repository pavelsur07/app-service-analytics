<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\StockPlacement\BuildStockPlacementReportAction;
use App\Ingestion\Infrastructure\Query\StockPlacement\StockPlacementCursor;
use App\Ingestion\Infrastructure\Query\StockPlacement\StockPlacementQuery;
use App\Ingestion\Infrastructure\Query\StockPlacement\StockPlacementRow;
use App\Ingestion\Infrastructure\Query\StockPlacement\StockPlacementSql;
use App\Ingestion\Ui\Response\StockPlacement\StockPlacementDefinitionsResponse;
use App\Ingestion\Ui\Response\StockPlacement\StockPlacementItemResponse;
use App\Ingestion\Ui\Response\StockPlacement\StockPlacementReportResponse;
use App\Shared\Ui\QueryParameter;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(
    '/api/companies/{companyId}/stock-placement',
    name: 'ingestion_stock_placement_report',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ShowStockPlacementReportController
{
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(private readonly BuildStockPlacementReportAction $buildReport)
    {
    }

    #[OA\Parameter(name: 'target_days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: StockPlacementSql::DEFAULT_TARGET_DAYS, minimum: StockPlacementSql::MIN_TARGET_DAYS, maximum: StockPlacementSql::MAX_TARGET_DAYS))]
    #[OA\Parameter(name: 'lead_days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: StockPlacementSql::DEFAULT_LEAD_DAYS, minimum: StockPlacementSql::MIN_LEAD_DAYS, maximum: StockPlacementSql::MAX_LEAD_DAYS))]
    #[OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: StockPlacementSql::STATUSES))]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: StockPlacementQuery::DEFAULT_LIMIT, minimum: 1, maximum: StockPlacementQuery::MAX_LIMIT))]
    #[OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Остатки Ozon FBO по кластерам и рекомендация поставок', content: new Model(type: StockPlacementReportResponse::class))]
    #[OA\Response(response: 403, description: 'Пользователь не состоит в этой компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Некорректные параметры отчёта', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        $targetDays = QueryParameter::int($request, 'target_days', StockPlacementSql::DEFAULT_TARGET_DAYS);
        if (null === $targetDays || $targetDays < StockPlacementSql::MIN_TARGET_DAYS || $targetDays > StockPlacementSql::MAX_TARGET_DAYS) {
            return self::invalid('invalid_target_days', 'target_days must be an integer between 7 and 90.');
        }
        $leadDays = QueryParameter::int($request, 'lead_days', StockPlacementSql::DEFAULT_LEAD_DAYS);
        if (null === $leadDays || $leadDays < StockPlacementSql::MIN_LEAD_DAYS || $leadDays > StockPlacementSql::MAX_LEAD_DAYS) {
            return self::invalid('invalid_lead_days', 'lead_days must be an integer between 0 and 60.');
        }
        $status = null;
        if ($request->query->has('status')) {
            $status = (string) $request->query->get('status');
            if (!\in_array($status, StockPlacementSql::STATUSES, true)) {
                return self::invalid('invalid_status', 'status must be one of: '.implode(', ', StockPlacementSql::STATUSES).'.');
            }
        }
        $limit = QueryParameter::int($request, 'limit', StockPlacementQuery::DEFAULT_LIMIT);
        if (null === $limit || $limit < 1 || $limit > StockPlacementQuery::MAX_LIMIT) {
            return self::invalid('invalid_limit', 'limit must be an integer between 1 and 200.');
        }

        $today = (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->setTime(0, 0);
        $cursor = null;
        if ($request->query->has('cursor')) {
            $cursor = StockPlacementCursor::decode((string) $request->query->get('cursor'));
            // Курсор того же расчёта: день (сегодня или вчера — после
            // полуночи страницу продолжают в том же дне), параметры и фильтр.
            if (
                null === $cursor
                || $cursor->targetDays !== $targetDays
                || $cursor->leadDays !== $leadDays
                || $cursor->status !== $status
                || !\in_array($cursor->today, [$today->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d')], true)
            ) {
                return self::invalid('invalid_cursor', 'cursor is malformed.');
            }
            $today = new \DateTimeImmutable($cursor->today, new \DateTimeZone(self::TIMEZONE));
        }

        $report = ($this->buildReport)($companyId, $today, $targetDays, $leadDays, $status, $limit, $cursor);

        return new JsonResponse(new StockPlacementReportResponse(
            today: $today->format('Y-m-d'),
            definitions: new StockPlacementDefinitionsResponse(
                targetDays: $targetDays,
                leadDays: $leadDays,
                demandWindowDays: StockPlacementSql::DEMAND_WINDOW_DAYS,
                minSales: StockPlacementSql::MIN_SALES,
                surplusFactor: StockPlacementSql::SURPLUS_FACTOR,
                abcABps: StockPlacementSql::ABC_A_BPS,
                abcBBps: StockPlacementSql::ABC_B_BPS,
                demandBasis: 'delivery_cluster_sales_excluding_cancelled',
                stockBasis: 'latest_complete_snapshot_including_pickup_points',
            ),
            snapshotDate: $report->snapshotDate,
            completeSnapshotDays: $report->completeSnapshotDays,
            correctionApplied: $report->correctionApplied,
            deficitPositions: $report->deficitPositions,
            deficitUnits: $report->deficitUnits,
            surplusPositions: $report->surplusPositions,
            recommendedPositions: $report->recommendedPositions,
            recommendedUnits: $report->recommendedUnits,
            items: array_map(
                static fn (StockPlacementRow $row): StockPlacementItemResponse => new StockPlacementItemResponse(
                    marketplaceSku: $row->marketplaceSku,
                    offerId: $row->offerId,
                    name: $row->name,
                    cluster: $row->cluster,
                    available: $row->available,
                    transit: $row->transit,
                    requested: $row->requested,
                    sold: $row->sold,
                    demandMilliPerDay: $row->demandMilliPerDay,
                    coverDays: $row->coverDays,
                    recommended: $row->recommended,
                    status: $row->status,
                    abcClass: $row->abcClass,
                    zeroDays: $row->zeroDays,
                    lostHours: $row->lostHours,
                    adsCluster: $row->adsCluster,
                    idcCluster: $row->idcCluster,
                ),
                $report->items,
            ),
            nextCursor: $report->nextCursor?->encode(),
        ));
    }

    private static function invalid(string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            new ValidationErrorResponse(Response::HTTP_UNPROCESSABLE_ENTITY, $code, $message),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
