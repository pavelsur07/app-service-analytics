<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Controller;

use App\SalesPlanning\Application\ApproveSalesPlanAction;
use App\SalesPlanning\Application\ApproveSalesPlanOutcome;
use App\SalesPlanning\Ui\Request\SalesPlanRequest;
use App\SalesPlanning\Ui\Response\EditOutcomeResponse;
use App\SalesPlanning\Ui\Response\SalesPlanApprovedResponse;
use App\SalesPlanning\Ui\Response\SalesPlanDayConflictResponse;
use App\Shared\Ui\RequestAttributes;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/companies/{companyId}/sales-plans/{planId}/approve', name: 'sales_planning_plan_approve', requirements: ['companyId' => Requirement::UUID, 'planId' => Requirement::UUID], methods: ['POST'])]
final readonly class ApproveSalesPlanController
{
    public function __construct(private ApproveSalesPlanAction $approve)
    {
    }

    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['version'], properties: [new OA\Property(property: 'version', type: 'integer', minimum: 1)]))]
    #[OA\Response(response: 200, description: 'Утверждение первой версии либо замена активной версии', content: new Model(type: SalesPlanApprovedResponse::class))]
    #[OA\Response(response: 404, description: 'План не найден у компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 409, description: 'Конфликт версии, статуса либо занятости дней', content: new OA\JsonContent(oneOf: [new OA\Schema(ref: new Model(type: ValidationErrorResponse::class)), new OA\Schema(ref: new Model(type: SalesPlanDayConflictResponse::class))]))]
    #[OA\Response(response: 422, description: 'Пустой план, отсутствие метрики или неверное тело запроса', content: new Model(type: ValidationErrorResponse::class))]
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
        $result = ($this->approve)($companyId, $planId, $version, $actor);

        return match ($result->outcome) {
            ApproveSalesPlanOutcome::ClosedLineage => EditOutcomeResponse::error(409, $result->outcome->value, 'Линия плана закрыта либо исходная версия больше не активна.'),
            ApproveSalesPlanOutcome::Approved => new JsonResponse(new SalesPlanApprovedResponse((string) $result->id, (int) $result->version)),
            ApproveSalesPlanOutcome::NotFound => EditOutcomeResponse::error(404, $result->outcome->value, 'План не найден.'),
            ApproveSalesPlanOutcome::VersionConflict => EditOutcomeResponse::error(409, $result->outcome->value, 'План изменил кто-то ещё. Перечитайте план.'),
            ApproveSalesPlanOutcome::NotDraft => EditOutcomeResponse::error(409, $result->outcome->value, 'Утверждается только черновик.'),
            ApproveSalesPlanOutcome::MissingMetric => EditOutcomeResponse::error(422, $result->outcome->value, 'Перед утверждением выберите метрику цели.'),
            ApproveSalesPlanOutcome::EmptyPlan => EditOutcomeResponse::error(422, $result->outcome->value, 'Пустой план нельзя утвердить.'),
            ApproveSalesPlanOutcome::DayConflict => new JsonResponse(new SalesPlanDayConflictResponse($result->conflicts), 409),
        };
    }
}
