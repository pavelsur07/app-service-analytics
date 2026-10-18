<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

use App\SalesPlanning\Application\EditSalesPlanOutcome;
use App\SalesPlanning\Application\EditSalesPlanResult;
use App\SalesPlanning\Domain\SkuPeriodPlan;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ответ на правку черновика: правка дня и удаление SKU отказывают
 * одинаково, и перевод исхода в HTTP один на оба контроллера.
 */
final class EditOutcomeResponse
{
    public static function of(EditSalesPlanResult $result): JsonResponse
    {
        return match ($result->outcome) {
            EditSalesPlanOutcome::Saved => new JsonResponse(new SalesPlanVersionResponse((int) $result->version)),
            EditSalesPlanOutcome::NotFound => self::error(Response::HTTP_NOT_FOUND, 'sales_plan_not_found', 'План не найден.'),
            EditSalesPlanOutcome::VersionConflict => self::error(Response::HTTP_CONFLICT, 'version_conflict', 'План изменил кто-то ещё. Обновите страницу и повторите.'),
            EditSalesPlanOutcome::NotEditable => self::error(Response::HTTP_CONFLICT, 'sales_plan_not_editable', 'Правится только черновик.'),
            EditSalesPlanOutcome::SkuNotInPlan => self::error(Response::HTTP_UNPROCESSABLE_ENTITY, 'sku_not_in_plan', 'Этого артикула нет в плане.'),
            EditSalesPlanOutcome::DayOutOfPeriod => self::error(Response::HTTP_UNPROCESSABLE_ENTITY, 'day_out_of_period', 'День вне периода плана.'),
            EditSalesPlanOutcome::TotalTooLarge => self::error(Response::HTTP_UNPROCESSABLE_ENTITY, 'total_too_large', \sprintf('Штук SKU за период больше %d.', SkuPeriodPlan::MaxTotalQuantity)),
        };
    }

    public static function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
