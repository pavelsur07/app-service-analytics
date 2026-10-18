<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Controller;

use App\SalesPlanning\Application\SetSalesPlanSkusAction;
use App\SalesPlanning\Application\SetSalesPlanSkusOutcome;
use App\SalesPlanning\Domain\SalesPlan;
use App\SalesPlanning\Ui\Request\SalesPlanRequest;
use App\SalesPlanning\Ui\Response\SalesPlanVersionResponse;
use App\SalesPlanning\Ui\Response\UnknownSkusResponse;
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
 * Ввод SKU за период в черновик (ADR-040 п. 7): штуки раскладываются
 * по дням, цена копируется в каждый день. SKU, уже стоящий в плане,
 * раскладывается заново.
 */
#[Route(
    '/api/companies/{companyId}/sales-plans/{planId}/skus',
    name: 'sales_planning_plan_skus_set',
    requirements: ['companyId' => Requirement::UUID, 'planId' => Requirement::UUID],
    methods: ['PUT'],
)]
final class SetSalesPlanSkusController
{
    public function __construct(
        private readonly SetSalesPlanSkusAction $setSkus,
    ) {
    }

    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['version', 'items'],
        properties: [
            new OA\Property(property: 'version', type: 'integer', description: 'Версия плана из ответа чтения (ADR-008)'),
            new OA\Property(
                property: 'items',
                type: 'array',
                maxItems: SetSalesPlanSkusAction::MaxItemsPerRequest,
                minItems: 1,
                items: new OA\Items(
                    required: ['marketplaceSku', 'totalQuantity', 'priceMinor'],
                    properties: [
                        new OA\Property(property: 'marketplaceSku', type: 'string', maxLength: 64),
                        new OA\Property(property: 'totalQuantity', type: 'integer', minimum: 0, description: 'Штук за весь период'),
                        new OA\Property(property: 'priceMinor', type: 'integer', minimum: 1, description: 'РРЦ за штуку в минорных единицах валюты плана'),
                    ],
                    type: 'object',
                ),
            ),
        ],
    ))]
    #[OA\Response(response: 200, description: 'Сохранено; новая версия плана', content: new Model(type: SalesPlanVersionResponse::class))]
    #[OA\Response(response: 404, description: 'У компании нет такого плана', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 409, description: 'План изменил кто-то ещё либо он уже не черновик', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Тело некорректно, SKU нет в каталоге подключения или превышен лимит плана', content: new Model(type: UnknownSkusResponse::class))]
    #[OA\Response(response: 403, description: 'Пользователь не состоит в этой компании', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, string $planId, Request $request): JsonResponse
    {
        try {
            $input = SalesPlanRequest::skusFromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getMessage(), 'Проверьте версию, артикулы, штуки и цены.');
        }

        $actorUserId = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actorUserId));

        try {
            $result = ($this->setSkus)($companyId, $planId, $input['version'], $input['items'], $actorUserId);
        } catch (\InvalidArgumentException $invalid) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'items_invalid', $invalid->getMessage());
        }

        return match ($result->outcome) {
            SetSalesPlanSkusOutcome::Saved => new JsonResponse(new SalesPlanVersionResponse((int) $result->version)),
            SetSalesPlanSkusOutcome::NotFound => $this->error(Response::HTTP_NOT_FOUND, 'sales_plan_not_found', 'План не найден.'),
            SetSalesPlanSkusOutcome::VersionConflict => $this->error(Response::HTTP_CONFLICT, 'version_conflict', 'План изменил кто-то ещё. Обновите страницу и повторите.'),
            SetSalesPlanSkusOutcome::NotEditable => $this->error(Response::HTTP_CONFLICT, 'sales_plan_not_editable', 'Правится только черновик.'),
            SetSalesPlanSkusOutcome::UnknownSkus => new JsonResponse(
                new UnknownSkusResponse(Response::HTTP_UNPROCESSABLE_ENTITY, 'unknown_skus', 'Этих артикулов нет в каталоге подключения.', $result->unknownSkus),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            ),
            SetSalesPlanSkusOutcome::TooManySkus => $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'too_many_skus', \sprintf('В плане не больше %d артикулов.', SalesPlan::MaxSkus)),
        };
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
