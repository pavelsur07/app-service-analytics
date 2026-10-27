<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

use App\SalesPlanning\Application\CloseSalesPlanOutcome;
use App\SalesPlanning\Application\CloseSalesPlanResult;
use Symfony\Component\HttpFoundation\JsonResponse;

final class CloseSalesPlanOutcomeResponse
{
    public static function of(CloseSalesPlanResult $result): JsonResponse
    {
        return match ($result->outcome) {
            CloseSalesPlanOutcome::Changed => new JsonResponse(new CloseSalesPlanResponse((string) $result->id, (int) $result->version, (string) $result->status?->value)),
            CloseSalesPlanOutcome::NotFound => EditOutcomeResponse::error(404, $result->outcome->value, 'План не найден.'),
            CloseSalesPlanOutcome::VersionConflict => EditOutcomeResponse::error(409, $result->outcome->value, 'План изменил кто-то ещё. Перечитайте план.'),
            CloseSalesPlanOutcome::InvalidStatus => EditOutcomeResponse::error(409, $result->outcome->value, 'Статус плана не допускает этот переход.'),
        };
    }
}
