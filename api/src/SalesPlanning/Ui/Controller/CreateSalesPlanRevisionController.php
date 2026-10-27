<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Controller;

use App\SalesPlanning\Application\CreateSalesPlanRevisionAction;
use App\SalesPlanning\Application\CreateSalesPlanRevisionOutcome;
use App\SalesPlanning\Ui\Request\SalesPlanRequest;
use App\SalesPlanning\Ui\Response\EditOutcomeResponse;
use App\SalesPlanning\Ui\Response\SalesPlanRevisionResponse;
use App\Shared\Ui\RequestAttributes;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/companies/{companyId}/sales-plans/{planId}/revisions', name: 'sales_planning_plan_revision_create', requirements: ['companyId' => Requirement::UUID, 'planId' => Requirement::UUID], methods: ['POST'])]
final readonly class CreateSalesPlanRevisionController
{
    public function __construct(private CreateSalesPlanRevisionAction $createRevision)
    {
    }

    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['version'], properties: [new OA\Property(property: 'version', type: 'integer', minimum: 1)]))]
    #[OA\Response(response: 200, description: 'Новый либо существующий открытый черновик линии', content: new Model(type: SalesPlanRevisionResponse::class))]
    #[OA\Response(response: 404, description: 'План не найден у компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 409, description: 'Конфликт версии либо источник не утверждён/линия закрыта', content: new Model(type: ValidationErrorResponse::class))]
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
        $result = ($this->createRevision)($companyId, $planId, $version, $actor);
        if (CreateSalesPlanRevisionOutcome::Created === $result->outcome) {
            $draft = $result->draft;
            \assert(null !== $draft);

            return new JsonResponse(new SalesPlanRevisionResponse($draft->id()->toRfc4122(), $draft->version(), $draft->versionNumber()));
        }

        return match ($result->outcome) {
            CreateSalesPlanRevisionOutcome::NotFound => EditOutcomeResponse::error(404, $result->outcome->value, 'План не найден.'),
            CreateSalesPlanRevisionOutcome::VersionConflict => EditOutcomeResponse::error(409, $result->outcome->value, 'План изменил кто-то ещё. Перечитайте план.'),
            CreateSalesPlanRevisionOutcome::NotApproved => EditOutcomeResponse::error(409, $result->outcome->value, 'Новая версия создаётся только из активного утверждённого плана.'),
        };
    }
}
