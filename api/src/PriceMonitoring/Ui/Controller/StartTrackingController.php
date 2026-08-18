<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Ui\Controller;

use App\PriceMonitoring\Application\StartTrackingAction;
use App\PriceMonitoring\Domain\StartTrackingOutcome;
use App\PriceMonitoring\Ui\Request\StartTrackingRequest;
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
 * «Добавить в отслеживание» из оверлей-панели расширения (ADR-014).
 *
 * Путь под /api/extension/ — отдельный firewall с токеном (ADR-010),
 * сессия здесь не работает. companyId первым сегментом (CLAUDE.md §1):
 * членство проверяет CompanyAccessSubscriber, совпадение с компанией
 * токена — ExtensionTokenScopeSubscriber. Обе проверки до контроллера,
 * и первый же из них ставит автора в атрибуты запроса.
 *
 * Успех — всегда 200, без различия «завёл» и «уже отслеживается».
 * 201 не сообщил бы клиенту ничего: кнопка в обоих случаях показывает
 * одно, а различить вставку и обновление внутри одного `ON CONFLICT`
 * можно только трюком с `xmax` — цена, которую нечем оправдать.
 */
#[Route(
    '/api/extension/companies/{companyId}/tracked-skus',
    name: 'price_monitoring_extension_start_tracking',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['POST'],
)]
final class StartTrackingController
{
    public function __construct(
        private readonly StartTrackingAction $startTracking,
    ) {
    }

    #[OA\Post(security: [['ExtensionToken' => []]])]
    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['marketplaceSku'],
        properties: [
            new OA\Property(property: 'marketplaceSku', type: 'string', description: 'Артикул площадки с открытой карточки'),
        ],
    ))]
    #[OA\Response(response: 200, description: 'Артикул отслеживается; повторный вызов не создаёт второй записи')]
    #[OA\Response(
        response: 409,
        description: 'У компании больше одного активного подключения Ozon — к какому привязать, решать не нам',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Некорректный артикул, нет активного подключения Ozon либо достигнут потолок отслеживаемых артикулов',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    // 401 — часть контракта, а не деталь реализации: клиент обязан
    // отличить «токен умер, переподключись» от прочих отказов, и форма
    // тела у этого ответа определена (ValidationErrorResponse).
    #[OA\Response(
        response: 401,
        description: 'Токен отсутствует или недействителен',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 403,
        description: 'Компания недоступна этому токену',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        try {
            $tracking = StartTrackingRequest::fromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getMessage(), 'Проверьте артикул.');
        }

        // Автора ставит CompanyAccessSubscriber там же, где проверяет
        // членство: сюда запрос без него не доходит.
        $actorUserId = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actorUserId));

        $outcome = ($this->startTracking)(
            $companyId,
            $tracking->marketplaceSku,
            $actorUserId,
            new \DateTimeImmutable(),
        );

        return match ($outcome) {
            // 200 с телом `null`, а не 204: клиент всегда разбирает ответ
            // как JSON, и пустое тело уронило бы разбор на успехе.
            StartTrackingOutcome::Tracked => new JsonResponse(null),
            StartTrackingOutcome::NoActiveOzonConnection => $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'no_active_ozon_connection',
                'Подключение Ozon неактивно. Проверьте его в приложении и повторите.',
            ),
            StartTrackingOutcome::MultipleOzonConnections => $this->error(
                Response::HTTP_CONFLICT,
                'multiple_ozon_connections',
                'У компании несколько активных подключений Ozon — отслеживание для такой конфигурации пока не поддержано.',
            ),
            StartTrackingOutcome::LimitReached => $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'tracked_sku_limit_reached',
                \sprintf('Больше %d артикулов одновременно не отслеживается: расширение не успеет обойти их за полчаса. Остановите ненужные.', StartTrackingAction::MAX_TRACKED),
            ),
        };
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
