<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Controller;

use App\SalesPlanning\Application\ReadSalesPlanCalendarAction;
use App\SalesPlanning\Ui\Response\EditOutcomeResponse;
use App\SalesPlanning\Ui\Response\SalesPlanCalendarResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/companies/{companyId}/sales-plans/{planId}/calendar', name: 'sales_planning_calendar_show', requirements: ['companyId' => Requirement::UUID, 'planId' => Requirement::UUID], methods: ['GET'])]
final readonly class ShowSalesPlanCalendarController
{
    public function __construct(private ReadSalesPlanCalendarAction $calendar)
    {
    }

    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 50, minimum: 1, maximum: 200))]
    #[OA\Parameter(name: 'cursor', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 1024))]
    #[OA\Response(response: 200, description: 'Страница календаря одной версии плана и вычисляемые границы сезона', content: new Model(type: SalesPlanCalendarResponse::class))]
    #[OA\Response(response: 403, description: 'Нет членства в компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 404, description: 'План не найден', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Неверный лимит или курсор, включая смену версии при чтении страниц', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, string $planId, Request $request): JsonResponse
    {
        try {
            $query = $request->query->all();
            $limit = $query['limit'] ?? '50';
            $cursor = $query['cursor'] ?? null;
            if ([] !== array_diff(array_keys($query), ['limit', 'cursor']) || !\is_string($limit) || 1 !== preg_match('/^[1-9]\d{0,2}$/D', $limit) || (null !== $cursor && !\is_string($cursor))) {
                throw new \InvalidArgumentException('Нужны limit от 1 до 200 и строковый cursor.');
            }
            $page = ($this->calendar)($companyId, $planId, (int) $limit, $cursor);

            return null === $page ? EditOutcomeResponse::error(404, 'sales_plan_not_found', 'План не найден.') : new JsonResponse(SalesPlanCalendarResponse::of($page));
        } catch (\InvalidArgumentException $invalid) {
            return EditOutcomeResponse::error(422, 'invalid_calendar_request', $invalid->getMessage());
        }
    }
}
