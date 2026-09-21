<?php

declare(strict_types=1);

namespace App\Planning\Ui\Controller;

use App\Planning\Application\ReadDailyPlanAction;
use App\Planning\Domain\DailyPlanMutationOutcome;
use App\Planning\Ui\Request\DailyPlanParameters;
use App\Planning\Ui\Request\ReadDailyPlanRequest;
use App\Planning\Ui\Response\DailyPlanItemResponse;
use App\Planning\Ui\Response\DailyPlanListResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route(
    '/api/companies/{companyId}/planning/accounts/{accountId}/skus/{sku}/plan',
    name: 'planning_daily_plan_read',
    requirements: ['companyId' => Requirement::UUID, 'accountId' => Requirement::UUID, 'sku' => '.+'],
    methods: ['GET'],
)]
final readonly class ReadDailyPlanController
{
    public function __construct(
        private ReadDailyPlanAction $read,
        private ValidatorInterface $validator,
    ) {
    }

    #[OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(response: 200, description: 'Дневной план за период', content: new Model(type: DailyPlanListResponse::class))]
    #[OA\Response(response: 404, description: 'Кабинет не принадлежит компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Некорректный SKU или период', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 403, description: 'Пользователь не состоит в компании', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, string $accountId, string $sku, Request $request): JsonResponse
    {
        try {
            $sku = DailyPlanParameters::sku($sku);
            $input = ReadDailyPlanRequest::fromRequest($request);
        } catch (\InvalidArgumentException $invalid) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getMessage(), 'Проверьте SKU и период плана.');
        }
        if (0 !== \count($this->validator->validate($input))) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'request_invalid', 'Проверьте период плана.');
        }
        try {
            [$from, $to] = $input->validPeriod();
            $result = ($this->read)($companyId, $accountId, $sku, $from, $to);
        } catch (\InvalidArgumentException $invalid) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getMessage(), 'Проверьте SKU и период плана.');
        }

        return match ($result->outcome) {
            DailyPlanMutationOutcome::Saved => new JsonResponse(new DailyPlanListResponse(array_map(DailyPlanItemResponse::fromItem(...), $result->items))),
            DailyPlanMutationOutcome::AccountNotFound => $this->error(Response::HTTP_NOT_FOUND, 'marketplace_account_not_found', 'Кабинет маркетплейса не найден.'),
            DailyPlanMutationOutcome::UnknownSku => $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'marketplace_sku_unknown', 'SKU не найден в этом кабинете.'),
            DailyPlanMutationOutcome::VersionConflict => throw new \LogicException('Чтение плана не создаёт конфликт версий.'),
        };
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
