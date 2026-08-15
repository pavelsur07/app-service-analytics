<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\SetListingCostAction;
use App\Ingestion\Domain\ListingCostOutcome;
use App\Ingestion\Ui\Request\ListingCostRequest;
use App\Shared\Domain\ValueObject\Money;
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
 * Новая себестоимость, действующая с даты (ADR-013).
 *
 * Отдельный эндпоинт от исправления, а не флаг в теле: у операций
 * противоположные последствия для прошлого. Новая цена его не трогает,
 * исправление — переписывает уже показанную прибыль. Один адрес на оба
 * случая означал бы, что ввод сегодняшней закупки способен молча
 * изменить отчёт за прошлый месяц.
 */
#[Route(
    '/api/companies/{companyId}/listing-costs',
    name: 'ingestion_listing_cost_set',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['POST'],
)]
final class SetListingCostController
{
    public function __construct(
        private readonly SetListingCostAction $setCost,
    ) {
    }

    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['marketplaceAccountId', 'marketplaceSku', 'effectiveFrom', 'unitCostMinor', 'currency'],
        properties: [
            new OA\Property(property: 'marketplaceAccountId', type: 'string', format: 'uuid'),
            new OA\Property(property: 'marketplaceSku', type: 'string'),
            new OA\Property(property: 'effectiveFrom', type: 'string', format: 'date', description: 'С какой бизнес-даты цена действует'),
            new OA\Property(property: 'unitCostMinor', type: 'integer', description: 'Себестоимость единицы в минорных единицах — копейках (ADR-004)'),
            new OA\Property(property: 'currency', type: 'string', description: 'Код валюты ISO 4217; умолчания нет'),
        ],
    ))]
    #[OA\Response(response: 201, description: 'Цена сохранена')]
    #[OA\Response(
        response: 409,
        description: 'Цена с этой датой уже задана — её нужно исправлять, а не заводить вторую',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Тело запроса некорректно',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 403,
        description: 'Пользователь не состоит в этой компании',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        try {
            $cost = ListingCostRequest::fromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getMessage(), 'Проверьте карточку, дату, сумму и валюту.');
        }

        // Автора ставит CompanyAccessSubscriber там же, где проверяет
        // членство: сюда запрос без него не доходит.
        $actorUserId = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actorUserId));

        $outcome = ($this->setCost)(
            $companyId,
            $cost->marketplaceAccountId,
            $cost->marketplaceSku,
            $cost->effectiveFrom,
            Money::ofMinor($cost->unitCostMinor, $cost->currency),
            $actorUserId,
        );

        return match ($outcome) {
            ListingCostOutcome::Saved => new JsonResponse(null, Response::HTTP_CREATED),
            ListingCostOutcome::AlreadySetForThatDate => $this->error(
                Response::HTTP_CONFLICT,
                'cost_already_set_for_date',
                'С этой даты цена уже задана. Откройте её и исправьте — вторая цена на тот же день сделала бы выбор между ними делом случая.',
            ),
            // Остальные исходы этот сценарий не возвращает: позиция
            // создаётся здесь же, искать и сверять версию нечего.
            ListingCostOutcome::NotFound, ListingCostOutcome::VersionConflict => $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'unexpected_outcome',
                'Не удалось сохранить цену.',
            ),
        };
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
