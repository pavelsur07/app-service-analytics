<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Controller;

use App\SalesPlanning\Application\CreateSalesPlanAction;
use App\SalesPlanning\Ui\Request\SalesPlanRequest;
use App\SalesPlanning\Ui\Response\SalesPlanCreatedResponse;
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
 * Новый план продаж — черновик без строк (ADR-040). companyId первым
 * сегментом (CLAUDE.md §1); членство проверяет CompanyAccessSubscriber
 * до контроллера.
 */
#[Route(
    '/api/companies/{companyId}/sales-plans',
    name: 'sales_planning_plan_create',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['POST'],
)]
final class CreateSalesPlanController
{
    public function __construct(
        private readonly CreateSalesPlanAction $createPlan,
    ) {
    }

    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['marketplaceAccountId', 'name', 'dateFrom', 'dateTo', 'currency'],
        properties: [
            new OA\Property(property: 'marketplaceAccountId', type: 'string', format: 'uuid'),
            new OA\Property(property: 'name', type: 'string', maxLength: 200),
            new OA\Property(property: 'dateFrom', type: 'string', format: 'date', description: 'Первый день периода, календарь площадки'),
            new OA\Property(property: 'dateTo', type: 'string', format: 'date', description: 'Последний день периода включительно; период до 184 дней'),
            new OA\Property(property: 'currency', type: 'string', description: 'ISO 4217, одна на план (ADR-004)'),
        ],
    ))]
    #[OA\Response(response: 201, description: 'Черновик создан', content: new Model(type: SalesPlanCreatedResponse::class))]
    #[OA\Response(response: 422, description: 'Тело некорректно, период или подключение не годятся', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 403, description: 'Пользователь не состоит в этой компании', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        try {
            $input = SalesPlanRequest::creationFromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return $this->error($invalid->getMessage(), 'Проверьте подключение, название, даты и валюту.');
        }

        $actorUserId = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actorUserId));

        try {
            $plan = ($this->createPlan)(
                $companyId,
                $input['marketplaceAccountId'],
                $input['name'],
                $input['dateFrom'],
                $input['dateTo'],
                $input['currency'],
                $actorUserId,
            );
        } catch (\InvalidArgumentException $invalid) {
            return $this->error('plan_invalid', $invalid->getMessage());
        }

        if (null === $plan) {
            return $this->error('marketplace_account_not_found', 'У компании нет такого действующего подключения.');
        }

        return new JsonResponse(new SalesPlanCreatedResponse($plan->id()->toRfc4122(), $plan->version()), Response::HTTP_CREATED);
    }

    private function error(string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            new ValidationErrorResponse(Response::HTTP_UNPROCESSABLE_ENTITY, $code, $message),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
