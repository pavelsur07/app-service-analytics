<?php

declare(strict_types=1);

namespace App\Planning\Ui\Controller;

use App\Planning\Application\ApplyPlanImportAction;
use App\Planning\Application\PlanImportApplyOutcome;
use App\Planning\Ui\Response\PlanImportApplyResponse;
use App\Planning\Ui\Response\PlanImportApplySummaryResponse;
use App\Shared\Ui\RequestAttributes;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/companies/{companyId}/planning/accounts/{accountId}/imports/{previewId}/apply', name: 'planning_import_apply', requirements: ['companyId' => Requirement::UUID, 'accountId' => Requirement::UUID, 'previewId' => Requirement::UUID], methods: ['POST'])]
final readonly class ApplyPlanImportController
{
    public function __construct(private ApplyPlanImportAction $apply)
    {
    }

    #[OA\Response(response: 200, description: 'Импорт применён', content: new Model(type: PlanImportApplyResponse::class))]
    #[OA\Response(response: 403, description: 'Нет доступа к компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 404, description: 'Preview или кабинет не найден', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 409, description: 'План изменился после preview', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, string $accountId, string $previewId, Request $request): JsonResponse
    {
        $actorId = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actorId));
        $result = ($this->apply)($companyId, $accountId, $previewId, $actorId);

        return match ($result->outcome) {
            PlanImportApplyOutcome::Applied => new JsonResponse(new PlanImportApplyResponse($previewId, self::summary($result->summary ?? throw new \LogicException('Нет результата импорта.')))),
            PlanImportApplyOutcome::Conflict => new JsonResponse(new ValidationErrorResponse(409, 'import_version_conflict', 'План изменился. Создайте preview заново.'), 409),
            PlanImportApplyOutcome::NotFound => new JsonResponse(new ValidationErrorResponse(404, 'import_preview_not_found', 'Preview не найден или истёк.'), 404),
            PlanImportApplyOutcome::AccountNotFound => new JsonResponse(new ValidationErrorResponse(404, 'marketplace_account_not_found', 'Кабинет маркетплейса не найден.'), 404),
        };
    }

    /** @param array{created: int, updated: int, unchanged: int} $summary */
    private static function summary(array $summary): PlanImportApplySummaryResponse
    {
        return new PlanImportApplySummaryResponse($summary['created'], $summary['updated'], $summary['unchanged']);
    }
}
