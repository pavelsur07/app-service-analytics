<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Ui\Controller;

use App\PriceMonitoring\Application\ListPriceOverviewAction;
use App\PriceMonitoring\Application\PriceOverviewRow;
use App\PriceMonitoring\Ui\Response\PriceOverviewItemResponse;
use App\PriceMonitoring\Ui\Response\PriceOverviewListResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Экран отслеживания цен: артикул, цена кабинета, витринная цена
 * и соинвест между ними (ADR-014).
 *
 * Сессионный контур `/api/companies/...`, не расширенческий: это экран
 * приложения, и токен расширения сюда не ходит. Членство проверяет
 * CompanyAccessSubscriber до контроллера.
 *
 * Пагинации нет, лимит есть. Число отслеживаемых артикулов ограничено
 * сверху потолком в 50 на компанию (StartTrackingAction::MAX_TRACKED) —
 * это экран, который читают глазами, и листать в нём нечего. Но список
 * без предела не отдаётся никогда (CLAUDE.md §5), поэтому потолок
 * объявлен здесь явно и с запасом: если потолок отслеживания однажды
 * поднимут, экран не начнёт молча обрезать строки — он упрётся
 * в собственный предел, и это будет видно.
 */
#[Route(
    '/api/companies/{companyId}/prices',
    name: 'price_monitoring_overview',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ListPriceOverviewController
{
    private const int DEFAULT_LIMIT = 50;
    private const int MAX_LIMIT = 200;

    public function __construct(
        private readonly ListPriceOverviewAction $overview,
    ) {
    }

    #[OA\Parameter(
        name: 'limit',
        in: 'query',
        required: false,
        schema: new OA\Schema(type: 'integer', default: self::DEFAULT_LIMIT, maximum: self::MAX_LIMIT, minimum: 1),
    )]
    #[OA\Response(
        response: 200,
        description: 'Отслеживаемые артикулы с ценами и соинвестом',
        content: new Model(type: PriceOverviewListResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Некорректный limit',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 403,
        description: 'Пользователь не состоит в этой компании',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        $limit = self::DEFAULT_LIMIT;
        if ($request->query->has('limit')) {
            $limit = (int) $request->query->get('limit');
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            return new JsonResponse(
                new ValidationErrorResponse(
                    status: Response::HTTP_UNPROCESSABLE_ENTITY,
                    code: 'invalid_limit',
                    message: \sprintf('limit must be an integer between 1 and %d.', self::MAX_LIMIT),
                ),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $items = array_map(
            static fn (PriceOverviewRow $row): PriceOverviewItemResponse => new PriceOverviewItemResponse(
                marketplaceSku: $row->marketplaceSku,
                name: $row->name,
                sellerPriceMinor: $row->sellerPrice?->minorAmount(),
                displayedPriceMinor: $row->displayedPrice?->minorAmount(),
                coInvestmentMinor: $row->coInvestment?->minorAmount(),
                // Валюта одна на строку: обе цены одного товара
                // в одной валюте, иначе разницу считать нельзя вовсе
                // (ADR-004), и Money::minus уже бросил бы исключение.
                currency: $row->displayedPrice?->currency() ?? $row->sellerPrice?->currency(),
                observedAt: $row->observedAt?->format(\DateTimeInterface::ATOM),
            ),
            ($this->overview)($companyId, $limit),
        );

        return new JsonResponse(new PriceOverviewListResponse(items: $items));
    }
}
