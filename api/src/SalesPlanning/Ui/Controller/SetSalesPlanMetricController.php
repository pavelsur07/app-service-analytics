<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Controller;

use App\SalesPlanning\Application\SetSalesPlanMetricAction;
use App\SalesPlanning\Application\SetSalesPlanMetricOutcome;
use App\SalesPlanning\Domain\SalesPlanMetric;
use App\SalesPlanning\Ui\Request\SalesPlanRequest;
use App\SalesPlanning\Ui\Response\EditOutcomeResponse;
use App\SalesPlanning\Ui\Response\SalesPlanMetricResponse;
use App\Shared\Ui\RequestAttributes;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/companies/{companyId}/sales-plans/{planId}/metric', name: 'sales_planning_plan_metric', requirements: ['companyId' => Requirement::UUID, 'planId' => Requirement::UUID], methods: ['PUT'])]
final readonly class SetSalesPlanMetricController
{
    public function __construct(private SetSalesPlanMetricAction $setMetric)
    {
    }

    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['version', 'metric'], properties: [new OA\Property(property: 'version', type: 'integer', minimum: 1), new OA\Property(property: 'metric', type: 'string', enum: SalesPlanMetric::class)]))]
    #[OA\Response(response: 200, description: 'Метрика выбрана явно', content: new Model(type: SalesPlanMetricResponse::class))]
    #[OA\Response(response: 404, description: 'План не найден у компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 409, description: 'Конфликт версии, статуса либо неизменяемой метрики линии', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Неверное тело запроса или метрика', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 403, description: 'Нет членства в компании', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, string $planId, Request $request): JsonResponse
    {
        try {
            $input = SalesPlanRequest::metricFromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return EditOutcomeResponse::error(422, $invalid->getMessage(), 'Нужны положительная целочисленная version и допустимая metric.');
        }
        $actor = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actor));
        $result = ($this->setMetric)($companyId, $planId, $input['version'], $input['metric'], $actor);

        return match ($result->outcome) {
            SetSalesPlanMetricOutcome::Saved => new JsonResponse(new SalesPlanMetricResponse($planId, (int) $result->version, $input['metric'])),
            SetSalesPlanMetricOutcome::NotFound => EditOutcomeResponse::error(404, $result->outcome->value, 'План не найден.'),
            SetSalesPlanMetricOutcome::NotEditable => EditOutcomeResponse::error(409, $result->outcome->value, 'Метрика меняется только у черновика. Для утверждённого плана нужна новая версия.'),
            SetSalesPlanMetricOutcome::VersionConflict => EditOutcomeResponse::error(409, $result->outcome->value, 'План изменил кто-то ещё. Перечитайте план.'),
            SetSalesPlanMetricOutcome::MetricImmutable => EditOutcomeResponse::error(409, $result->outcome->value, 'Метрика линии уже выбрана. Для другой метрики создайте новый план.'),
        };
    }
}
