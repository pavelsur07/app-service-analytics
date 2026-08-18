<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Ui\Controller;

use App\PriceMonitoring\Domain\TrackedSkuRepository;
use App\PriceMonitoring\Ui\Request\StartTrackingRequest;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * «Остановить отслеживание» из оверлей-панели (ADR-014).
 *
 * POST, не DELETE: строка не удаляется — она переходит в `stopped`,
 * потому что наблюдения цены ссылаются на артикул и подключение.
 *
 * Сценария в Application нет намеренно: оркестрировать нечего, это один
 * условный UPDATE. Синхронные сценарии вызываются напрямую из Ui
 * (docs/patterns.md), а промежуточный класс, только пробрасывающий три
 * аргумента, — слой ради симметрии.
 *
 * 404, если активной записи нет. Повторная остановка тоже 404: неверно
 * было бы отвечать успехом на просьбу остановить то, что не отслеживается,
 * — экран показал бы кнопку, которой там быть не должно.
 */
#[Route(
    '/api/extension/companies/{companyId}/tracked-skus/{marketplaceSku}/stop',
    name: 'price_monitoring_extension_stop_tracking',
    requirements: ['companyId' => Requirement::UUID, 'marketplaceSku' => StartTrackingRequest::SKU_PATTERN],
    methods: ['POST'],
)]
final class StopTrackingController
{
    public function __construct(
        private readonly TrackedSkuRepository $trackedSkus,
    ) {
    }

    #[OA\Post(security: [['ExtensionToken' => []]])]
    #[OA\Response(response: 200, description: 'Отслеживание остановлено')]
    #[OA\Response(
        response: 404,
        description: 'Этот артикул компания не отслеживает',
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
    public function __invoke(string $companyId, string $marketplaceSku): JsonResponse
    {
        if ($this->trackedSkus->stopIfActive($companyId, $marketplaceSku, new \DateTimeImmutable())) {
            return new JsonResponse(null);
        }

        return new JsonResponse(
            new ValidationErrorResponse(
                status: Response::HTTP_NOT_FOUND,
                code: 'tracked_sku_not_found',
                message: 'Этот артикул не отслеживается.',
            ),
            Response::HTTP_NOT_FOUND,
        );
    }
}
