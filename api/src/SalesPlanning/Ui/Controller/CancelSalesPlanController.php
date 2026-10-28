<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Controller;

use App\SalesPlanning\Application\CancelSalesPlanAction;
use App\SalesPlanning\Ui\Request\SalesPlanRequest;
use App\SalesPlanning\Ui\Response\CloseSalesPlanOutcomeResponse;
use App\SalesPlanning\Ui\Response\CloseSalesPlanResponse;
use App\SalesPlanning\Ui\Response\EditOutcomeResponse;
use App\Shared\Ui\RequestAttributes;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/companies/{companyId}/sales-plans/{planId}/cancel', name: 'sales_planning_plan_cancel', requirements: ['companyId' => Requirement::UUID, 'planId' => Requirement::UUID], methods: ['POST'])]
final readonly class CancelSalesPlanController
{
    public function __construct(private CancelSalesPlanAction $action)
    {
    }

    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['version'], properties: [new OA\Property(property: 'version', type: 'integer', minimum: 1)]))]
    #[OA\Response(response: 200, description: 'План отменён; открытый черновик закрыт, дни освобождены', content: new Model(type: CloseSalesPlanResponse::class))]
    #[OA\Response(response: 404, description: 'План не найден у компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 409, description: 'Конфликт версии либо статуса', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Неверное тело запроса', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 403, description: 'Нет членства в компании', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, string $planId, Request $request): JsonResponse
    {
        try {
            $version = SalesPlanRequest::approvalVersionFromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return EditOutcomeResponse::error(422, $invalid->getMessage(), 'Нужна положительная целочисленная версия плана в JSON.');
        }
        $actor = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actor));

        return CloseSalesPlanOutcomeResponse::of(($this->action)($companyId, $planId, $version, $actor));
    }
}
