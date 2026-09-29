<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Infrastructure\Query\Listings\SkuForecastFactSkuRow;
use App\Ingestion\Infrastructure\Query\Listings\SkuForecastFactSkuSearchQuery;
use App\Ingestion\Ui\Response\SkuForecastFactSkuResponse;
use App\Ingestion\Ui\Response\SkuForecastFactSkuSearchResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(
    '/api/companies/{companyId}/sku-forecast-fact/skus',
    name: 'ingestion_sku_forecast_fact_skus',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['GET'],
)]
final class SearchSkuForecastFactSkusController
{
    public function __construct(private readonly SkuForecastFactSkuSearchQuery $query)
    {
    }

    #[OA\Parameter(name: 'q', in: 'query', required: false, description: 'Поиск каталога по части SKU, названия или артикула. Для истории заказов поиск по части SKU требует три последовательных буквы или цифры; более короткий запрос ищет точный SKU.', schema: new OA\Schema(type: 'string', maxLength: 100))]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: SkuForecastFactSkuSearchQuery::DEFAULT_LIMIT, minimum: 1, maximum: SkuForecastFactSkuSearchQuery::MAX_LIMIT))]
    #[OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'SKU компании из каталога и истории заказов', content: new Model(type: SkuForecastFactSkuSearchResponse::class))]
    #[OA\Response(response: 403, description: 'Пользователь не состоит в этой компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Некорректные параметры поиска', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        $rawQuery = $request->query->all()['q'] ?? '';
        if (!\is_string($rawQuery) || 1 !== preg_match('//u', $rawQuery) || mb_strlen($rawQuery, 'UTF-8') > 100 || 1 === preg_match('/[\x00-\x1F\x7F]/u', $rawQuery)) {
            return self::invalid('invalid_q', 'q must be a UTF-8 string up to 100 characters.');
        }
        $query = trim($rawQuery);
        $rawLimit = $request->query->all()['limit'] ?? null;
        if (null !== $rawLimit && (!\is_string($rawLimit) || 1 !== preg_match('/^\d+$/', $rawLimit))) {
            return self::invalid('invalid_limit', 'limit must be an integer between 1 and 200.');
        }
        $limit = null === $rawLimit ? SkuForecastFactSkuSearchQuery::DEFAULT_LIMIT : (int) $rawLimit;
        if ($limit < 1 || $limit > SkuForecastFactSkuSearchQuery::MAX_LIMIT) {
            return self::invalid('invalid_limit', 'limit must be an integer between 1 and 200.');
        }

        $cursor = null;
        if ($request->query->has('cursor')) {
            $rawCursor = $request->query->all()['cursor'];
            if (!\is_string($rawCursor)) {
                return self::invalid('invalid_cursor', 'cursor is malformed.');
            }
            $cursor = self::decodeCursor($rawCursor, $query);
            if (null === $cursor) {
                return self::invalid('invalid_cursor', 'cursor is malformed.');
            }
        }

        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $this->query->build($companyId, $query, $cursor, $limit)->executeQuery()->fetchAllAssociative();
        $hasMore = \count($rawRows) > $limit;
        $rows = array_map(SkuForecastFactSkuSearchQuery::mapRow(...), \array_slice($rawRows, 0, $limit));
        $last = [] === $rows ? null : $rows[\count($rows) - 1];

        return new JsonResponse(new SkuForecastFactSkuSearchResponse(
            items: array_map(static fn (SkuForecastFactSkuRow $row): SkuForecastFactSkuResponse => new SkuForecastFactSkuResponse($row->marketplaceSku, $row->name, $row->offerId), $rows),
            nextCursor: $hasMore && null !== $last ? self::encodeCursor($query, $last->marketplaceSku) : null,
        ));
    }

    private static function encodeCursor(string $query, string $sku): string
    {
        return base64_encode(json_encode(['q' => $query, 'sku' => $sku], \JSON_THROW_ON_ERROR));
    }

    private static function decodeCursor(string $cursor, string $query): ?string
    {
        $decoded = base64_decode($cursor, true);
        if (false === $decoded) {
            return null;
        }
        try {
            $value = json_decode($decoded, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($value) || ($value['q'] ?? null) !== $query || !\is_string($value['sku'] ?? null) || '' === $value['sku'] || mb_strlen($value['sku'], 'UTF-8') > 64) {
            return null;
        }

        return $value['sku'];
    }

    private static function invalid(string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse(Response::HTTP_UNPROCESSABLE_ENTITY, $code, $message), Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
