<?php

declare(strict_types=1);

namespace App\Planning\Ui\Controller;

use App\Planning\Application\DailyPlanMutationResult;
use App\Planning\Application\RemoveDailyPlanAction;
use App\Planning\Domain\DailyPlan;
use App\Planning\Domain\DailyPlanMutationOutcome;
use App\Planning\Ui\Request\DailyPlanParameters;
use App\Planning\Ui\Request\RemoveDailyPlanRequest;
use App\Planning\Ui\Response\DailyPlanConflictResponse;
use App\Planning\Ui\Response\DailyPlanItemResponse;
use App\Shared\Ui\RequestAttributes;
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
    '/api/companies/{companyId}/planning/accounts/{accountId}/skus/{sku}/plan/{date}',
    name: 'planning_daily_plan_remove',
    requirements: ['companyId' => Requirement::UUID, 'accountId' => Requirement::UUID, 'sku' => '.+'],
    methods: ['DELETE'],
)]
final readonly class RemoveDailyPlanController
{
    public function __construct(
        private RemoveDailyPlanAction $remove,
        private ValidatorInterface $validator,
    ) {
    }

    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['expectedVersion'], properties: [new OA\Property(property: 'expectedVersion', type: 'integer', maximum: DailyPlan::MAX_MUTABLE_VERSION, minimum: 0)], additionalProperties: false))]
    #[OA\Response(response: 200, description: 'План удалён; версия сохранена', content: new Model(type: DailyPlanItemResponse::class))]
    #[OA\Response(response: 404, description: 'Кабинет не принадлежит компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 409, description: 'Версия устарела', content: new Model(type: DailyPlanConflictResponse::class))]
    #[OA\Response(response: 422, description: 'Некорректные данные или неизвестный SKU', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 403, description: 'Пользователь не состоит в компании', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, string $accountId, string $sku, string $date, Request $request): JsonResponse
    {
        try {
            $input = RemoveDailyPlanRequest::fromJson($request->getContent());
            $sku = DailyPlanParameters::sku($sku);
            $businessDate = DailyPlanParameters::date($date);
        } catch (\InvalidArgumentException $invalid) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getMessage(), 'Проверьте версию, SKU и дату.');
        }
        if (0 !== \count($this->validator->validate($input))) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'request_invalid', 'Проверьте версию плана.');
        }
        $actorId = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actorId));

        return $this->respond(($this->remove)($companyId, $accountId, $sku, $businessDate, $input->validExpectedVersion(), $actorId));
    }

    private function respond(DailyPlanMutationResult $result): JsonResponse
    {
        return match ($result->outcome) {
            DailyPlanMutationOutcome::Saved => new JsonResponse(DailyPlanItemResponse::fromItem($result->current ?? throw new \LogicException('Нет результата удаления.'))),
            DailyPlanMutationOutcome::AccountNotFound => $this->error(Response::HTTP_NOT_FOUND, 'marketplace_account_not_found', 'Кабинет маркетплейса не найден.'),
            DailyPlanMutationOutcome::UnknownSku => $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'marketplace_sku_unknown', 'SKU не найден в этом кабинете.'),
            DailyPlanMutationOutcome::VersionConflict => new JsonResponse(new DailyPlanConflictResponse(Response::HTTP_CONFLICT, 'version_conflict', 'План изменил кто-то ещё. Обновите данные и повторите.', DailyPlanItemResponse::fromItem($result->current ?? throw new \LogicException('Нет актуальной версии плана.'))), Response::HTTP_CONFLICT),
        };
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
