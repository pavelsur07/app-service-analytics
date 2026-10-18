<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Controller;

use App\SalesPlanning\Application\ChangeSalesPlanDayAction;
use App\SalesPlanning\Domain\SalesPlanLine;
use App\SalesPlanning\Domain\SkuPeriodPlan;
use App\SalesPlanning\Ui\Request\SalesPlanRequest;
use App\SalesPlanning\Ui\Response\EditOutcomeResponse;
use App\SalesPlanning\Ui\Response\SalesPlanVersionResponse;
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
 * Правка одного дня SKU в черновике (ADR-040 п. 7): штуки и цена дня.
 */
#[Route(
    '/api/companies/{companyId}/sales-plans/{planId}/days',
    name: 'sales_planning_plan_day_change',
    requirements: ['companyId' => Requirement::UUID, 'planId' => Requirement::UUID],
    methods: ['PUT'],
)]
final class ChangeSalesPlanDayController
{
    public function __construct(
        private readonly ChangeSalesPlanDayAction $changeDay,
    ) {
    }

    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['version', 'marketplaceSku', 'date', 'quantity', 'priceMinor'],
        properties: [
            new OA\Property(property: 'version', type: 'integer', description: 'Версия плана из ответа чтения (ADR-008)'),
            new OA\Property(property: 'marketplaceSku', type: 'string', maxLength: 64),
            new OA\Property(property: 'date', type: 'string', format: 'date'),
            new OA\Property(property: 'quantity', type: 'integer', maximum: SkuPeriodPlan::MaxTotalQuantity, minimum: 0),
            new OA\Property(property: 'priceMinor', type: 'integer', maximum: SalesPlanLine::MaxPriceMinor, minimum: 1, description: 'РРЦ за штуку в минорных единицах валюты плана'),
        ],
    ))]
    #[OA\Response(response: 200, description: 'Сохранено; новая версия плана', content: new Model(type: SalesPlanVersionResponse::class))]
    #[OA\Response(response: 404, description: 'У компании нет такого плана', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 409, description: 'План изменил кто-то ещё либо он уже не черновик', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Тело некорректно, день вне периода, SKU нет в плане или превышен потолок штук', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 403, description: 'Пользователь не состоит в этой компании', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, string $planId, Request $request): JsonResponse
    {
        try {
            $input = SalesPlanRequest::dayFromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return EditOutcomeResponse::error(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getMessage(), 'Проверьте версию, артикул, дату, штуки и цену.');
        }

        $actorUserId = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actorUserId));

        try {
            $result = ($this->changeDay)(
                $companyId,
                $planId,
                $input['version'],
                $input['marketplaceSku'],
                $input['date'],
                $input['quantity'],
                $input['priceMinor'],
                $actorUserId,
            );
        } catch (\InvalidArgumentException $invalid) {
            return EditOutcomeResponse::error(Response::HTTP_UNPROCESSABLE_ENTITY, 'day_invalid', $invalid->getMessage());
        }

        return EditOutcomeResponse::of($result);
    }
}
