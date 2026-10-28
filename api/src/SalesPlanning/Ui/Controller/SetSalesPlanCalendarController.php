<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Controller;

use App\SalesPlanning\Application\SetSalesPlanCalendarAction;
use App\SalesPlanning\Application\SetSalesPlanCalendarOutcome;
use App\SalesPlanning\Ui\Request\SalesPlanCalendarRequest;
use App\SalesPlanning\Ui\Response\EditOutcomeResponse;
use App\SalesPlanning\Ui\Response\SalesPlanVersionResponse;
use App\Shared\Ui\RequestAttributes;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/companies/{companyId}/sales-plans/{planId}/calendar', name: 'sales_planning_calendar_set', requirements: ['companyId' => Requirement::UUID, 'planId' => Requirement::UUID], methods: ['PUT'])]
final readonly class SetSalesPlanCalendarController
{
    public function __construct(private SetSalesPlanCalendarAction $setCalendar)
    {
    }

    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['version', 'entries'], properties: [
        new OA\Property(property: 'version', type: 'integer', minimum: 1, maximum: 2147483647),
        new OA\Property(property: 'entries', description: 'Полный набор ручных событий. Пустой массив удаляет все; границы сезона вычисляются отдельно.', type: 'array', maxItems: 500, items: new OA\Items(type: 'object', required: ['kind', 'marketplaceSku', 'dateFrom', 'dateTo'], properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'marketplaceSku', type: 'string', maxLength: 64, nullable: true),
            new OA\Property(property: 'kind', type: 'string', enum: ['replenishment', 'sales', 'replenishment_stop']),
            new OA\Property(property: 'dateFrom', type: 'string', format: 'date'),
            new OA\Property(property: 'dateTo', type: 'string', format: 'date'),
        ])),
    ]))]
    #[OA\Response(response: 200, description: 'Календарь сохранён, новая версия шапки', content: new Model(type: SalesPlanVersionResponse::class))]
    #[OA\Response(response: 403, description: 'Нет членства в компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 404, description: 'План не найден', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 409, description: 'Версия изменилась или план доступен только для чтения', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Недопустимые события, SKU или идентификаторы', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, string $planId, Request $request): JsonResponse
    {
        try {
            $input = SalesPlanCalendarRequest::fromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return EditOutcomeResponse::error(422, 'invalid_calendar_request', $invalid->getMessage());
        }
        $actor = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actor));
        $result = ($this->setCalendar)($companyId, $planId, $input['version'], $input['entries'], $actor);

        return match ($result->outcome) {
            SetSalesPlanCalendarOutcome::Saved => new JsonResponse(new SalesPlanVersionResponse((int) $result->version)),
            SetSalesPlanCalendarOutcome::NotFound => EditOutcomeResponse::error(404, 'sales_plan_not_found', 'План не найден.'),
            SetSalesPlanCalendarOutcome::VersionConflict => EditOutcomeResponse::error(409, 'version_conflict', 'План изменился. Перечитайте календарь, сохранив свои правки.'),
            SetSalesPlanCalendarOutcome::NotEditable => EditOutcomeResponse::error(409, 'sales_plan_not_editable', 'Изменяется только календарь черновика. Создайте новую версию.'),
            SetSalesPlanCalendarOutcome::InvalidEntries => EditOutcomeResponse::error(422, 'calendar_entries_invalid', 'Проверьте даты, повторы и идентификаторы событий.'),
            SetSalesPlanCalendarOutcome::UnknownSku => EditOutcomeResponse::error(422, 'sku_not_in_plan', 'Один из артикулов отсутствует в плане.'),
        };
    }
}
