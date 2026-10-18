<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Controller;

use App\SalesPlanning\Application\SearchPlanCatalogSkusAction;
use App\SalesPlanning\Ui\Response\CatalogSkuListResponse;
use App\SalesPlanning\Ui\Response\CatalogSkuResponse;
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
 * Выбор SKU в план — каталог подключения (ADR-040 п. 4). Путь под
 * sales-plans, а не под каталогом Ingestion: это вход экрана плана,
 * а Ui в чужой модуль не ходит.
 */
#[Route(
    '/api/companies/{companyId}/sales-plans/catalog-skus',
    name: 'sales_planning_catalog_skus',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['GET'],
)]
final class SearchPlanCatalogSkusController
{
    private const int DEFAULT_LIMIT = 50;

    // Тот же потолок, что у self::MAX_LIMIT: Ui
    // в чужой модуль не ходит даже за константой, а превышение Facade
    // отклонил бы исключением, а не 422.
    private const int MAX_LIMIT = 200;

    public function __construct(
        private readonly SearchPlanCatalogSkusAction $searchSkus,
    ) {
    }

    #[OA\Parameter(name: 'marketplaceAccountId', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'search', in: 'query', description: 'Артикул площадки, артикул продавца или название', required: false, schema: new OA\Schema(type: 'string', maxLength: 100))]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_LIMIT, maximum: self::MAX_LIMIT, minimum: 1))]
    #[OA\Parameter(name: 'cursor', in: 'query', description: 'nextCursor предыдущей страницы', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Страница каталога подключения', content: new Model(type: CatalogSkuListResponse::class))]
    #[OA\Response(response: 422, description: 'Некорректные параметры', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 403, description: 'Пользователь не состоит в этой компании', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        $accountId = $request->query->get('marketplaceAccountId');
        if (!\is_string($accountId) || !Uuid::isValid($accountId)) {
            return $this->error('marketplace_account_id_required', 'marketplaceAccountId must be a UUID.');
        }

        $limit = QueryParameter::int($request, 'limit', self::DEFAULT_LIMIT);
        if (null === $limit || $limit < 1 || $limit > self::MAX_LIMIT) {
            return $this->error('invalid_limit', \sprintf('limit must be an integer between 1 and %d.', self::MAX_LIMIT));
        }

        $search = $request->query->get('search', '');
        if (!\is_string($search) || mb_strlen($search) > 100) {
            return $this->error('invalid_search', 'search is longer than 100 characters.');
        }

        $rawCursor = $request->query->get('cursor');
        $cursor = \is_string($rawCursor) && '' !== $rawCursor ? $rawCursor : null;

        $page = ($this->searchSkus)($companyId, $accountId, $search, $cursor, $limit);

        return new JsonResponse(new CatalogSkuListResponse(
            items: array_map(
                static fn ($sku): CatalogSkuResponse => new CatalogSkuResponse($sku->marketplaceSku, $sku->offerId, $sku->name),
                $page->items,
            ),
            nextCursor: $page->nextCursor,
        ));
    }

    private function error(string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            new ValidationErrorResponse(Response::HTTP_UNPROCESSABLE_ENTITY, $code, $message),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
