<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Infrastructure\Query\SalesFactListQuery;
use App\Ingestion\Infrastructure\Query\SalesFactListRow;
use App\Ingestion\Ui\Response\SalesFactListItemResponse;
use App\Ingestion\Ui\Response\SalesFactListResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Пределы /docs/patterns.md, «Контракт списочного эндпоинта»: limit
 * по умолчанию 50, максимум 200, свыше — 422, не молчаливое обрезание.
 * companyId — первый сегмент маршрута (CLAUDE.md §1), не тело запроса.
 */
#[Route('/api/companies/{companyId}/ingestion/ozon/sales-facts', name: 'ingestion_ozon_sales_facts_list', methods: ['GET'])]
final class ListSalesFactsController
{
    private const int DEFAULT_LIMIT = 50;
    private const int MAX_LIMIT = 200;

    public function __construct(
        private readonly SalesFactListQuery $query,
    ) {
    }

    #[OA\Response(
        response: 200,
        description: 'Список продаж компании, keyset-пагинация',
        content: new Model(type: SalesFactListResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Некорректный limit или cursor',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        $limit = self::DEFAULT_LIMIT;
        if ($request->query->has('limit')) {
            $limit = (int) $request->query->get('limit');
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            return $this->validationError(
                'invalid_limit',
                \sprintf('limit must be an integer between 1 and %d.', self::MAX_LIMIT),
            );
        }

        $cursor = $request->query->get('cursor');

        try {
            $qb = $this->query->build($companyId, $cursor, $limit);
            /** @var list<array<string, mixed>> $rawRows */
            $rawRows = $qb->executeQuery()->fetchAllAssociative();
        } catch (\InvalidArgumentException|\JsonException) {
            return $this->validationError('invalid_cursor', 'cursor is malformed.');
        }

        $hasMore = \count($rawRows) > $limit;
        $rawRows = \array_slice($rawRows, 0, $limit);
        $rows = array_map(SalesFactListQuery::mapRow(...), $rawRows);

        $nextCursor = ($hasMore && [] !== $rows) ? SalesFactListQuery::encodeCursor($rows[\count($rows) - 1]) : null;

        $items = array_map(
            static fn (SalesFactListRow $row): SalesFactListItemResponse => new SalesFactListItemResponse(
                marketplaceAccountId: $row->marketplaceAccountId,
                sourceRowId: $row->sourceRowId,
                businessDate: $row->businessDate,
                status: $row->status,
                marketplaceSku: $row->marketplaceSku,
                quantity: $row->quantity,
                amountMinor: $row->amountMinor,
                commissionAmountMinor: $row->commissionAmountMinor,
                currency: $row->currency,
            ),
            $rows,
        );

        return new JsonResponse(new SalesFactListResponse(items: $items, nextCursor: $nextCursor));
    }

    private function validationError(string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            new ValidationErrorResponse(status: Response::HTTP_UNPROCESSABLE_ENTITY, code: $code, message: $message),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
