<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Ui\Controller;

use App\PriceMonitoring\Application\RecordPriceObservationAction;
use App\PriceMonitoring\Domain\RecordObservationOutcome;
use App\PriceMonitoring\Ui\Request\PriceObservationRequest;
use App\Shared\Domain\ValueObject\Money;
use App\Shared\Ui\RequestAttributes;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Приём снимка двух цен с карточки Ozon (ADR-014).
 *
 * Путь под /api/extension/ — отдельный firewall с токеном (ADR-010).
 * companyId первым сегментом (CLAUDE.md §1): членство проверяет
 * CompanyAccessSubscriber, совпадение с компанией токена —
 * ExtensionTokenScopeSubscriber, и первый из них ставит автора
 * в атрибуты запроса.
 *
 * Успех — 200 и на первой записи, и на повторе. Расширение повторяет
 * отправку после сетевого сбоя, и различать эти два случая ему незачем:
 * оба означают «снимок у нас».
 */
#[Route(
    '/api/extension/companies/{companyId}/price-observations',
    name: 'price_monitoring_extension_record_observation',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['POST'],
)]
final class RecordPriceObservationController
{
    public function __construct(
        private readonly RecordPriceObservationAction $recordObservation,
        private readonly RateLimiterFactoryInterface $priceObservationsLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[OA\Post(security: [['ExtensionToken' => []]])]
    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['marketplaceSku', 'observedAt', 'displayedPrice', 'extensionVersion'],
        properties: [
            new OA\Property(property: 'marketplaceSku', type: 'string', description: 'Артикул площадки с карточки'),
            new OA\Property(property: 'observedAt', type: 'string', format: 'date-time', description: 'Момент снимка, ISO 8601 в UTC (Date.toISOString)'),
            new OA\Property(
                property: 'displayedPrice',
                description: 'Витринная цена Ozon — то, что видит покупатель, до скидки банка. Цену продавца расширение не присылает: на карточке её нет (ADR-015)',
                properties: [
                    new OA\Property(property: 'amount', type: 'integer', description: 'Сумма в минорных единицах; дробные числа не принимаются (ADR-004)'),
                    new OA\Property(property: 'currency', type: 'string', description: 'Код ISO 4217'),
                ],
                type: 'object',
            ),
            new OA\Property(property: 'extensionVersion', type: 'string', description: 'Версия сборки расширения — по ней читаются массовые пропуски'),
        ],
    ))]
    #[OA\Response(response: 200, description: 'Снимок принят; повтор того же момента не создаёт второй строки')]
    #[OA\Response(
        response: 404,
        description: 'Компания этот артикул не отслеживает',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Тело запроса некорректно: артикул, момент, сумма или валюта',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 429,
        description: 'Слишком много наблюдений от компании за час; в Retry-After — когда повторить',
        content: new Model(type: ValidationErrorResponse::class),
    )]
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
        // Лимит до разбора тела: сбойная сборка расширения не должна
        // получать даже разбор JSON в цикле.
        $limit = $this->priceObservationsLimiter->create($companyId)->consume();
        if (!$limit->isAccepted()) {
            $response = $this->error(
                Response::HTTP_TOO_MANY_REQUESTS,
                'too_many_observations',
                'Слишком много наблюдений за час. Повторите позже.',
            );
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }

        try {
            $observation = PriceObservationRequest::fromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getMessage(), 'Проверьте артикул, момент снимка, суммы и валюту.');
        }

        // Автора ставит CompanyAccessSubscriber там же, где проверяет
        // членство: сюда запрос без него не доходит.
        $actorUserId = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actorUserId));

        $outcome = ($this->recordObservation)(
            $companyId,
            $observation->marketplaceSku,
            $observation->observedAt,
            Money::ofMinor($observation->displayedPriceMinor, $observation->currency),
            $actorUserId,
            $observation->extensionVersion,
            new \DateTimeImmutable(),
        );

        return match ($outcome) {
            // 200 с телом `null`, а не 204: клиент всегда разбирает ответ
            // как JSON, и пустое тело уронило бы разбор на успехе.
            RecordObservationOutcome::Recorded, RecordObservationOutcome::Duplicate => new JsonResponse(null),
            RecordObservationOutcome::NotTracked => $this->notTracked($observation->marketplaceSku),
        };
    }

    /**
     * Предупреждение, не ошибка (ADR-014): между открытием фонового окна
     * и отправкой снимка продавец мог нажать «Остановить», и это обычный
     * ход событий, а не аномалия. В трекер ошибок такое не уходит
     * намеренно — там оно было бы шумом, делающим невидимыми настоящие
     * ошибки (config/packages/monolog.yaml).
     *
     * Компанию и идентификатор запроса в запись добавляет
     * RequestContextProcessor, повторять их здесь не нужно.
     */
    private function notTracked(string $marketplaceSku): JsonResponse
    {
        $this->logger->warning('Наблюдение цены по неотслеживаемому артикулу', [
            'marketplace_sku' => $marketplaceSku,
        ]);

        return $this->error(
            Response::HTTP_NOT_FOUND,
            'tracked_sku_not_found',
            'Этот артикул не отслеживается.',
        );
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
