<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Controller;

use App\ProductResearchOzon\Domain\ResearchProblem;
use App\Shared\Ui\RequestAttributes;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

abstract class ResearchController
{
    public function __construct(private readonly bool $researchEnabled)
    {
    }

    /** @param callable(): JsonResponse $operation */
    protected function respond(Request $request, callable $operation): JsonResponse
    {
        try {
            if (!$this->researchEnabled) {
                throw new ResearchProblem('feature_disabled');
            }
            $actor = $request->attributes->get(RequestAttributes::ActorUserId);
            if (!\is_string($actor)) {
                throw new ResearchProblem('access_denied');
            }

            return $operation();
        } catch (ResearchProblem $problem) {
            $status = match ($problem->reason) {
                'feature_disabled', 'project_not_found', 'run_not_found' => 404,
                'access_denied' => 403,
                'version_conflict', 'preview_outdated', 'idempotency_conflict' => 409,
                'no_months_to_load' => 422,
                'catalog_not_ready' => 503,
                default => 500,
            };

            return new JsonResponse(new ValidationErrorResponse($status, $problem->reason, 'Операция недоступна; проверьте состояние проекта и повторите предпросмотр.'), $status);
        } catch (\InvalidArgumentException $invalid) {
            $known = ['invalid_request', 'unknown_field', 'invalid_selection', 'invalid_shortcut', 'invalid_period', 'invalid_month', 'invalid_version', 'period_limit', 'month_limit', 'unavailable_month', 'unknown_category', 'invalid_project', 'invalid_limit', 'invalid_cursor'];
            $code = \in_array($invalid->getMessage(), $known, true) ? $invalid->getMessage() : 'invalid_request';

            return new JsonResponse(new ValidationErrorResponse(422, $code, 'Проверьте поля, категорию и периоды проекта.'), 422);
        }
    }

    protected function actor(Request $request): string
    {
        $actor = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actor));

        return $actor;
    }
}
