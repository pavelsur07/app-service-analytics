<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Controller;

use App\SalesPlanning\Application\ListSalesPlansAction;
use App\SalesPlanning\Infrastructure\Query\SalesPlanRow;
use App\SalesPlanning\Infrastructure\Query\SalesPlansQuery;
use App\SalesPlanning\Ui\Response\SalesPlanListResponse;
use App\SalesPlanning\Ui\Response\SalesPlanResponse;
use App\Shared\Ui\QueryParameter;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Планы продаж компании, свежие сверху (ADR-040). companyId первым
 * сегментом (§1); keyset, лимит, превышение — 422 (§5).
 */
#[Route(
    '/api/companies/{companyId}/sales-plans',
    name: 'sales_planning_plan_list',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ListSalesPlansController
{
    public function __construct(
        private readonly ListSalesPlansAction $listPlans,
    ) {
    }

    #[OA\Parameter(name: 'marketplaceAccountId', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: SalesPlansQuery::DEFAULT_LIMIT, maximum: SalesPlansQuery::MAX_LIMIT, minimum: 1))]
    #[OA\Parameter(name: 'cursor', in: 'query', description: 'nextCursor предыдущей страницы', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Планы с итогами', content: new Model(type: SalesPlanListResponse::class))]
    #[OA\Response(response: 422, description: 'Некорректные limit, cursor или marketplaceAccountId', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 403, description: 'Пользователь не состоит в этой компании', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        $limit = QueryParameter::int($request, 'limit', SalesPlansQuery::DEFAULT_LIMIT);
        if (null === $limit || $limit < 1 || $limit > SalesPlansQuery::MAX_LIMIT) {
            return $this->error('invalid_limit', \sprintf('limit must be an integer between 1 and %d.', SalesPlansQuery::MAX_LIMIT));
        }

        $cursor = $this->uuidParameter($request, 'cursor');
        $accountId = $this->uuidParameter($request, 'marketplaceAccountId');
        if (false === $cursor || false === $accountId) {
            return $this->error('invalid_parameter', 'cursor and marketplaceAccountId must be UUIDs.');
        }

        $page = ($this->listPlans)($companyId, $accountId, $cursor, $limit);

        return new JsonResponse(new SalesPlanListResponse(
            items: array_map(static fn (SalesPlanRow $row): SalesPlanResponse => SalesPlanResponse::fromRow($row), $page->items),
            nextCursor: $page->nextCursor,
        ));
    }

    /**
     * @return string|false|null false — значение есть, но не UUID
     */
    private function uuidParameter(Request $request, string $name): string|false|null
    {
        $raw = $request->query->get($name);
        if (null === $raw || '' === $raw) {
            return null;
        }

        return \is_string($raw) && Uuid::isValid($raw) ? $raw : false;
    }

    private function error(string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            new ValidationErrorResponse(Response::HTTP_UNPROCESSABLE_ENTITY, $code, $message),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
