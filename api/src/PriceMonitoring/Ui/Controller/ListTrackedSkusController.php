<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Ui\Controller;

use App\PriceMonitoring\Infrastructure\Query\TrackedSkuRow;
use App\PriceMonitoring\Infrastructure\Query\TrackedSkusQuery;
use App\PriceMonitoring\Ui\Response\TrackedSkuListResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Список отслеживаемых артикулов (ADR-014). Service worker расширения
 * читает его в начале каждого цикла, не доверяя одному лишь
 * `chrome.storage`: список мог измениться не с этого устройства.
 *
 * Пагинация та же, что у /skus, — расширение уже умеет её листать.
 * Отдавать список без предела нельзя даже зная, что он короткий (§5).
 */
#[Route(
    '/api/extension/companies/{companyId}/tracked-skus',
    name: 'price_monitoring_extension_tracked_skus',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ListTrackedSkusController
{
    private const int DEFAULT_LIMIT = 50;
    private const int MAX_LIMIT = 200;

    public function __construct(
        private readonly TrackedSkusQuery $query,
    ) {
    }

    // Параметры запроса объявлены явно: без них сгенерированный
    // TypeScript-контракт получает `query?: never`, и потребитель
    // не может ни передать limit, ни увидеть его в типах.
    #[OA\Get(security: [['ExtensionToken' => []]])]
    #[OA\Parameter(
        name: 'limit',
        in: 'query',
        required: false,
        schema: new OA\Schema(type: 'integer', default: self::DEFAULT_LIMIT, maximum: self::MAX_LIMIT, minimum: 1),
    )]
    #[OA\Parameter(
        name: 'cursor',
        in: 'query',
        description: 'Артикул из nextCursor предыдущей страницы',
        required: false,
        schema: new OA\Schema(type: 'string'),
    )]
    #[OA\Response(
        response: 200,
        description: 'Отслеживаемые артикулы компании, keyset-пагинация по самому артикулу',
        content: new Model(type: TrackedSkuListResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Некорректный limit',
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

        $cursor = $request->query->get('cursor');

        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $this->query
            ->build($companyId, \is_string($cursor) ? $cursor : null, $limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $hasMore = \count($rawRows) > $limit;
        $rows = array_map(TrackedSkusQuery::mapRow(...), \array_slice($rawRows, 0, $limit));
        $items = array_map(static fn (TrackedSkuRow $row): string => $row->marketplaceSku, $rows);

        return new JsonResponse(new TrackedSkuListResponse(
            items: $items,
            nextCursor: ($hasMore && [] !== $items) ? $items[\count($items) - 1] : null,
        ));
    }
}
