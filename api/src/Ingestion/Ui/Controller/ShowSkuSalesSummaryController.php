<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Infrastructure\Query\SkuSalesSummaryQuery;
use App\Ingestion\Infrastructure\Query\SkuSalesSummaryRow;
use App\Ingestion\Ui\Response\SkuSalesSummaryResponse;
use App\Ingestion\Ui\Response\SkuSalesTotalResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Итог продаж по одному артикулу — то, что расширение показывает поверх
 * карточки товара.
 *
 * Пустой ответ не 404: артикул может быть своим и при этом без продаж
 * за окно. «Не мой товар» расширение определяет само, по выгруженному
 * списку артикулов, и сюда с чужими не ходит.
 */
#[Route(
    '/api/extension/companies/{companyId}/skus/{marketplaceSku}/sales',
    name: 'ingestion_extension_sku_sales',
    requirements: ['companyId' => Requirement::UUID, 'marketplaceSku' => '[A-Za-z0-9_-]{1,64}'],
    methods: ['GET'],
)]
final class ShowSkuSalesSummaryController
{
    private const int DEFAULT_DAYS = 30;
    // Год — потолок, а не настройка: за ним запрос перестаёт быть
    // «что происходит с товаром сейчас» и превращается в отчёт,
    // которому место на экране, а не в оверлее.
    private const int MAX_DAYS = 365;

    public function __construct(
        private readonly SkuSalesSummaryQuery $query,
    ) {
    }

    #[OA\Get(security: [['ExtensionToken' => []]])]
    #[OA\Parameter(
        name: 'days',
        in: 'query',
        description: 'Окно в днях по бизнес-дате площадки',
        required: false,
        schema: new OA\Schema(type: 'integer', default: self::DEFAULT_DAYS, maximum: self::MAX_DAYS, minimum: 1),
    )]
    #[OA\Response(
        response: 200,
        description: 'Заказанное и отменённое по артикулу за окно дней, по каждой валюте отдельно',
        content: new Model(type: SkuSalesSummaryResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Некорректный days',
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
    public function __invoke(string $companyId, string $marketplaceSku, Request $request): JsonResponse
    {
        $days = self::DEFAULT_DAYS;
        if ($request->query->has('days')) {
            $days = (int) $request->query->get('days');
        }
        if ($days < 1 || $days > self::MAX_DAYS) {
            return new JsonResponse(
                new ValidationErrorResponse(
                    status: Response::HTTP_UNPROCESSABLE_ENTITY,
                    code: 'invalid_days',
                    message: \sprintf('days must be an integer between 1 and %d.', self::MAX_DAYS),
                ),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $this->query
            ->build($companyId, $marketplaceSku, $days)
            ->executeQuery()
            ->fetchAllAssociative();

        $totals = array_map(
            static fn (SkuSalesSummaryRow $row): SkuSalesTotalResponse => new SkuSalesTotalResponse(
                currency: $row->currency,
                orderedQuantity: $row->orderedQuantity,
                orderedAmountMinor: $row->orderedAmountMinor,
                deliveredQuantity: $row->deliveredQuantity,
                deliveredAmountMinor: $row->deliveredAmountMinor,
                cancelledQuantity: $row->cancelledQuantity,
                cancelledAmountMinor: $row->cancelledAmountMinor,
            ),
            array_map(SkuSalesSummaryQuery::mapRow(...), $rawRows),
        );

        return new JsonResponse(new SkuSalesSummaryResponse(
            marketplaceSku: $marketplaceSku,
            days: $days,
            totals: $totals,
        ));
    }
}
