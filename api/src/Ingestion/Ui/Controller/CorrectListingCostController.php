<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\CorrectListingCostAction;
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
 * Исправление уже записанной себестоимости (ADR-013).
 *
 * Меняет уже показанную прибыль — в этом его смысл и отличие от новой
 * цены с новой даты. Экран обязан сказать об этом до нажатия: сколько
 * дней и сколько проданных штук затронет исправление.
 *
 * Ни карточка, ни дата начала действия здесь не меняются — это была бы
 * другая позиция, а не исправление этой.
 */
#[Route(
    '/api/companies/{companyId}/listing-costs/{costId}',
    name: 'ingestion_listing_cost_correct',
    requirements: ['companyId' => Requirement::UUID, 'costId' => Requirement::UUID],
    methods: ['PUT'],
)]
final class CorrectListingCostController
{
    public function __construct(
        private readonly CorrectListingCostAction $correctCost,
    ) {
    }

    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['unitCostMinor', 'currency', 'version'],
        properties: [
            new OA\Property(property: 'unitCostMinor', type: 'integer', description: 'Исправленная себестоимость в минорных единицах'),
            new OA\Property(property: 'currency', type: 'string', description: 'Та же валюта, что у позиции: смена валюты исправлением запрещена (ADR-004)'),
            new OA\Property(property: 'version', type: 'integer', description: 'Версия позиции из ответа списка (ADR-008)'),
        ],
    ))]
    #[OA\Response(response: 200, description: 'Исправлено; отчёты за прошедшие дни пересчитаны')]
    #[OA\Response(
        response: 404,
        description: 'У этой компании нет такой позиции',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 409,
        description: 'Позицию изменил кто-то ещё — перечитать и повторить (ADR-008)',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Тело запроса некорректно либо валюта отличается от валюты позиции',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 403,
        description: 'Пользователь не состоит в этой компании',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(string $companyId, string $costId, Request $request): JsonResponse
    {
        try {
            $correction = ListingCostRequest::correctionFromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getMessage(), 'Проверьте сумму, валюту и версию.');
        }

        $actorUserId = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actorUserId));

        try {
            $outcome = ($this->correctCost)(
                $companyId,
                $costId,
                Money::ofMinor($correction['unitCostMinor'], $correction['currency']),
                $correction['version'],
                $actorUserId,
            );
        } catch (\InvalidArgumentException) {
            // Смена валюты у существующей позиции — единственное, чем
            // сценарий отвечает исключением: это был бы пересчёт
            // по курсу, которого ADR-004 не допускает молча.
            return $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'currency_mismatch',
                'Валюту исправлением не меняют. Нужна другая валюта — заведите новую цену с даты.',
            );
        }

        return match ($outcome) {
            // 200 с телом `null`, а не 204: клиент фронтенда всегда
            // разбирает ответ как JSON, и пустое тело 204 уронило бы
            // разбор на успешном исправлении.
            ListingCostOutcome::Saved => new JsonResponse(null),
            ListingCostOutcome::NotFound => $this->error(
                Response::HTTP_NOT_FOUND,
                'listing_cost_not_found',
                'Позиция себестоимости не найдена.',
            ),
            ListingCostOutcome::VersionConflict => $this->error(
                Response::HTTP_CONFLICT,
                'version_conflict',
                'Цену изменил кто-то ещё. Обновите страницу и повторите.',
            ),
            // Этот сценарий существующую позицию правит и новой не заводит.
            ListingCostOutcome::AlreadySetForThatDate => $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'unexpected_outcome',
                'Не удалось исправить цену.',
            ),
        };
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
