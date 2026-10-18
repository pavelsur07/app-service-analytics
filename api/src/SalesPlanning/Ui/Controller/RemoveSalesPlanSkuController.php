<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Controller;

use App\SalesPlanning\Application\RemoveSalesPlanSkuAction;
use App\SalesPlanning\Ui\Response\EditOutcomeResponse;
use App\SalesPlanning\Ui\Response\SalesPlanVersionResponse;
use App\Shared\Ui\QueryParameter;
use App\Shared\Ui\RequestAttributes;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * SKU убирается из черновика целиком (ADR-040 п. 8). Версия — параметром
 * запроса: тело у DELETE прокси и клиенты передают ненадёжно.
 */
#[Route(
    '/api/companies/{companyId}/sales-plans/{planId}/skus/{marketplaceSku}',
    name: 'sales_planning_plan_sku_remove',
    requirements: ['companyId' => Requirement::UUID, 'planId' => Requirement::UUID, 'marketplaceSku' => '[^/]{1,64}'],
    methods: ['DELETE'],
)]
final class RemoveSalesPlanSkuController
{
    public function __construct(
        private readonly RemoveSalesPlanSkuAction $removeSku,
    ) {
    }

    #[OA\Parameter(name: 'version', in: 'query', description: 'Версия плана из ответа чтения (ADR-008)', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'SKU убран; новая версия плана', content: new Model(type: SalesPlanVersionResponse::class))]
    #[OA\Response(response: 404, description: 'У компании нет такого плана', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 409, description: 'План изменил кто-то ещё либо он уже не черновик', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Нет версии либо SKU нет в плане', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 403, description: 'Пользователь не состоит в этой компании', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, string $planId, string $marketplaceSku, Request $request): JsonResponse
    {
        $version = QueryParameter::int($request, 'version', -1);
        if (null === $version || $version < 0) {
            return EditOutcomeResponse::error(Response::HTTP_UNPROCESSABLE_ENTITY, 'version_required', 'Нужна версия плана.');
        }

        $actorUserId = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actorUserId));

        return EditOutcomeResponse::of(($this->removeSku)($companyId, $planId, $version, $marketplaceSku, $actorUserId));
    }
}
