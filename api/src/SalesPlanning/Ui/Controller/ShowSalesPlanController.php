<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Controller;

use App\SalesPlanning\Application\ShowSalesPlanAction;
use App\SalesPlanning\Infrastructure\Query\SalesPlanBucketRow;
use App\SalesPlanning\Infrastructure\Query\SalesPlanGrouping;
use App\SalesPlanning\Infrastructure\Query\SalesPlanSkuRow;
use App\SalesPlanning\Infrastructure\Query\SalesPlanSkusQuery;
use App\SalesPlanning\Ui\Response\SalesPlanBucketResponse;
use App\SalesPlanning\Ui\Response\SalesPlanDetailResponse;
use App\SalesPlanning\Ui\Response\SalesPlanResponse;
use App\SalesPlanning\Ui\Response\SalesPlanSkuResponse;
use App\Shared\Ui\QueryParameter;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * План для экрана (ADR-040 п. 3): шапка с версией и итогами, страница
 * SKU с итогом и корзинами выбранной группировки. Хранится день,
 * неделя и месяц — сумма дней.
 */
#[Route(
    '/api/companies/{companyId}/sales-plans/{planId}',
    name: 'sales_planning_plan_show',
    requirements: ['companyId' => Requirement::UUID, 'planId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ShowSalesPlanController
{
    public function __construct(
        private readonly ShowSalesPlanAction $showPlan,
    ) {
    }

    #[OA\Parameter(name: 'groupBy', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'period', enum: ['day', 'week', 'month', 'period']))]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: SalesPlanSkusQuery::DEFAULT_LIMIT, maximum: SalesPlanSkusQuery::MAX_LIMIT, minimum: 1))]
    #[OA\Parameter(name: 'cursor', in: 'query', description: 'nextCursor предыдущей страницы SKU', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'План со страницей SKU', content: new Model(type: SalesPlanDetailResponse::class))]
    #[OA\Response(response: 404, description: 'У компании нет такого плана', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Некорректные groupBy, limit или cursor', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 403, description: 'Пользователь не состоит в этой компании', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, string $planId, Request $request): JsonResponse
    {
        $rawGrouping = $request->query->get('groupBy', SalesPlanGrouping::Period->value);
        $grouping = \is_string($rawGrouping) ? SalesPlanGrouping::tryFrom($rawGrouping) : null;
        if (null === $grouping) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_group_by', 'groupBy must be day, week, month or period.');
        }

        $limit = QueryParameter::int($request, 'limit', SalesPlanSkusQuery::DEFAULT_LIMIT);
        if (null === $limit || $limit < 1 || $limit > SalesPlanSkusQuery::MAX_LIMIT) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_limit', \sprintf('limit must be an integer between 1 and %d.', SalesPlanSkusQuery::MAX_LIMIT));
        }

        $rawCursor = $request->query->get('cursor');
        $cursor = \is_string($rawCursor) && '' !== $rawCursor ? $rawCursor : null;
        if (null !== $cursor && \strlen($cursor) > 64) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_cursor', 'cursor is malformed.');
        }

        $view = ($this->showPlan)($companyId, $planId, $grouping, $cursor, $limit);
        if (null === $view) {
            return $this->error(Response::HTTP_NOT_FOUND, 'sales_plan_not_found', 'План не найден.');
        }

        return new JsonResponse(new SalesPlanDetailResponse(
            plan: SalesPlanResponse::fromRow($view->plan),
            groupBy: $grouping->value,
            skus: array_map(
                static fn (SalesPlanSkuRow $sku): SalesPlanSkuResponse => new SalesPlanSkuResponse(
                    marketplaceSku: $sku->marketplaceSku,
                    totalQuantity: $sku->totalQuantity,
                    revenueMinor: $sku->revenueMinor,
                    buckets: array_map(
                        static fn (SalesPlanBucketRow $bucket): SalesPlanBucketResponse => new SalesPlanBucketResponse(
                            dateFrom: $bucket->dateFrom,
                            dateTo: $bucket->dateTo,
                            quantity: $bucket->quantity,
                            revenueMinor: $bucket->revenueMinor,
                        ),
                        $view->buckets[$sku->marketplaceSku] ?? [],
                    ),
                ),
                $view->skus,
            ),
            nextCursor: $view->nextCursor,
        ));
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
