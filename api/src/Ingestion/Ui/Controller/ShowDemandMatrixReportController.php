<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\DemandMatrix\BuildDemandMatrixReportAction;
use App\Ingestion\Application\DemandMatrix\DemandMatrixReportItem;
use App\Ingestion\Infrastructure\Query\Demand\DemandSql;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixCellRow;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixClusterRow;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixCursor;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixSkuQuery;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixSql;
use App\Ingestion\Ui\Response\DemandMatrix\DemandMatrixCellResponse;
use App\Ingestion\Ui\Response\DemandMatrix\DemandMatrixClusterResponse;
use App\Ingestion\Ui\Response\DemandMatrix\DemandMatrixDefinitionsResponse;
use App\Ingestion\Ui\Response\DemandMatrix\DemandMatrixItemResponse;
use App\Ingestion\Ui\Response\DemandMatrix\DemandMatrixReportResponse;
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
    '/api/companies/{companyId}/demand-matrix',
    name: 'ingestion_demand_matrix_report',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ShowDemandMatrixReportController
{
    private const string TIMEZONE = 'Europe/Moscow';
    private const int MAX_SEARCH_LENGTH = 100;

    public function __construct(private readonly BuildDemandMatrixReportAction $buildReport)
    {
    }

    #[OA\Parameter(name: 'search', in: 'query', description: 'Подстрока артикула площадки, артикула продавца или названия', required: false, schema: new OA\Schema(type: 'string', maxLength: self::MAX_SEARCH_LENGTH))]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: DemandMatrixSkuQuery::DEFAULT_LIMIT, minimum: 1, maximum: DemandMatrixSkuQuery::MAX_LIMIT))]
    #[OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Матрица спроса Ozon: SKU × кластер покупателя', content: new Model(type: DemandMatrixReportResponse::class))]
    #[OA\Response(response: 403, description: 'Пользователь не состоит в этой компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Некорректные параметры отчёта', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        $search = $request->query->get('search', '');
        if (!\is_string($search) || mb_strlen($search) > self::MAX_SEARCH_LENGTH) {
            return self::invalid('invalid_search', 'search is longer than 100 characters.');
        }
        $limit = QueryParameter::int($request, 'limit', DemandMatrixSkuQuery::DEFAULT_LIMIT);
        if (null === $limit || $limit < 1 || $limit > DemandMatrixSkuQuery::MAX_LIMIT) {
            return self::invalid('invalid_limit', 'limit must be an integer between 1 and 200.');
        }

        $today = (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->setTime(0, 0);
        $cursor = null;
        if ($request->query->has('cursor')) {
            $cursor = DemandMatrixCursor::decode((string) $request->query->get('cursor'));
            // Курсор того же расчёта: день (сегодня или вчера — после
            // полуночи страницу продолжают в том же дне) и поиск.
            if (
                null === $cursor
                || $cursor->search !== $search
                || !\in_array($cursor->today, [$today->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d')], true)
            ) {
                return self::invalid('invalid_cursor', 'cursor is malformed.');
            }
            $today = new \DateTimeImmutable($cursor->today, new \DateTimeZone(self::TIMEZONE));
        }

        $report = ($this->buildReport)($companyId, $today, $search, $limit, $cursor);

        return new JsonResponse(new DemandMatrixReportResponse(
            today: $today->format('Y-m-d'),
            definitions: new DemandMatrixDefinitionsResponse(
                windowDays: DemandMatrixSql::WINDOW_DAYS,
                middleDays: DemandMatrixSql::MIDDLE_DAYS,
                shortDays: DemandMatrixSql::SHORT_DAYS,
                windowEnd: $today->modify('-1 day')->format('Y-m-d'),
                minSales: DemandMatrixSql::MIN_SALES,
                excludedStatuses: DemandSql::EXCLUDED_STATUSES,
                regionBasis: DemandSql::REGION_BASIS,
                periodBasis: DemandSql::PERIOD_BASIS,
                rounding: DemandMatrixSql::ROUNDING,
            ),
            clusters: array_map(
                static fn (DemandMatrixClusterRow $row): DemandMatrixClusterResponse => new DemandMatrixClusterResponse($row->cluster, $row->orders28),
                $report->clusters,
            ),
            incompleteSalesAccounts: $report->incompleteSalesAccounts,
            items: array_map(
                static fn (DemandMatrixReportItem $item): DemandMatrixItemResponse => new DemandMatrixItemResponse(
                    marketplaceSku: $item->sku->marketplaceSku,
                    offerId: $item->sku->offerId,
                    name: $item->sku->name,
                    orders7: $item->sku->orders7,
                    orders14: $item->sku->orders14,
                    orders28: $item->sku->orders28,
                    unknownClusterOrders28: $item->sku->unknownClusterOrders28,
                    demandMilliPerDay: $item->sku->demandMilliPerDay,
                    trendBps: $item->sku->trendBps,
                    cells: array_map(
                        static fn (DemandMatrixCellRow $cell): DemandMatrixCellResponse => new DemandMatrixCellResponse(
                            cluster: $cell->cluster,
                            orders7: $cell->orders7,
                            orders14: $cell->orders14,
                            orders28: $cell->orders28,
                            demandMilliPerDay: $cell->demandMilliPerDay,
                            shareBps: $cell->shareBps,
                            trendBps: $cell->trendBps,
                            stockoutDays: $cell->stockoutDays,
                            demandCensored: $cell->stockoutDays > 0,
                        ),
                        $item->cells,
                    ),
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
