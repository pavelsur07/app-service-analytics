<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\BuildBuyoutRateReportAction;
use App\Ingestion\Application\BuyoutRateSku;
use App\Ingestion\Infrastructure\Query\BuyoutRateCursor;
use App\Ingestion\Infrastructure\Query\BuyoutRateDirection;
use App\Ingestion\Infrastructure\Query\BuyoutRateQuery;
use App\Ingestion\Infrastructure\Query\BuyoutRateSort;
use App\Ingestion\Ui\Response\BuyoutRateItemResponse;
use App\Ingestion\Ui\Response\BuyoutRateListResponse;
use App\Ingestion\Ui\Response\BuyoutRateSummaryResponse;
use App\Shared\Ui\QueryParameter;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(
    '/api/companies/{companyId}/buyout-rate',
    name: 'ingestion_buyout_rate_list',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ListBuyoutRatesController
{
    private const int DEFAULT_DAYS = 30;
    private const array ALLOWED_DAYS = [7, 30, 90];
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(private readonly BuildBuyoutRateReportAction $buildReport)
    {
    }

    #[OA\Parameter(name: 'days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 30, enum: self::ALLOWED_DAYS))]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: BuyoutRateQuery::DEFAULT_LIMIT, minimum: 1, maximum: BuyoutRateQuery::MAX_LIMIT))]
    #[OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'ordered', enum: ['ordered', 'actual_buyout']))]
    #[OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'desc', enum: ['asc', 'desc']))]
    #[OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Процент выкупа и прогноз по SKU', content: new Model(type: BuyoutRateListResponse::class))]
    #[OA\Response(response: 403, description: 'Пользователь не состоит в этой компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Некорректные параметры списка', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        $days = QueryParameter::int($request, 'days', self::DEFAULT_DAYS);
        if (null === $days || !\in_array($days, self::ALLOWED_DAYS, true)) {
            return self::invalid('invalid_days', 'days must be one of: 7, 30, 90.');
        }

        $limit = QueryParameter::int($request, 'limit', BuyoutRateQuery::DEFAULT_LIMIT);
        if (null === $limit || $limit < 1 || $limit > BuyoutRateQuery::MAX_LIMIT) {
            return self::invalid('invalid_limit', 'limit must be an integer between 1 and 200.');
        }

        $sort = BuyoutRateSort::tryFrom($request->query->getString('sort', BuyoutRateSort::Ordered->value));
        if (null === $sort) {
            return self::invalid('invalid_sort', 'sort must be one of: ordered, actual_buyout.');
        }

        $direction = BuyoutRateDirection::tryFrom($request->query->getString('direction', BuyoutRateDirection::Desc->value));
        if (null === $direction) {
            return self::invalid('invalid_direction', 'direction must be one of: asc, desc.');
        }

        $cursor = null;
        if ($request->query->has('cursor')) {
            $rawCursor = (string) $request->query->get('cursor');
            $cursor = self::decodeCursor($rawCursor);
            if (null === $cursor || !$cursor->matches($sort, $direction, $days)) {
                return self::invalid('invalid_cursor', 'cursor is malformed.');
            }
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
        $to = $now->setTime(0, 0);
        $from = $to->modify('-'.($days - 1).' days');
        $report = ($this->buildReport)($companyId, $from, $to, $now, $limit, $sort, $direction, $days, $cursor);

        return new JsonResponse(new BuyoutRateListResponse(
            summary: new BuyoutRateSummaryResponse(
                orderedQuantity: $report->summary->orderedQuantity,
                resolvedQuantity: $report->summary->resolvedQuantity,
                projectedBuyoutQuantity: $report->summary->projectedBuyoutQuantity,
                projectedBuyoutRateBps: $report->summary->projectedBuyoutRateBps,
                resolutionRateBps: $report->summary->resolutionRateBps,
            ),
            items: array_map(self::item(...), $report->items),
            nextCursor: null === $report->nextCursor ? null : self::encodeCursor($report->nextCursor),
        ));
    }

    private static function item(BuyoutRateSku $item): BuyoutRateItemResponse
    {
        return new BuyoutRateItemResponse(
            marketplaceSku: $item->marketplaceSku,
            offerId: $item->offerId,
            name: $item->name,
            orderedQuantity: $item->orderedQuantity,
            resolvedQuantity: $item->resolvedQuantity,
            deliveredQuantity: $item->deliveredQuantity,
            actualBuyoutBaseQuantity: $item->deliveredQuantity + $item->t2Quantity + $item->partialReturnQuantity,
            actualBuyoutRateBps: $item->actualBuyoutRateBps,
            projectedBuyoutQuantity: $item->projectedBuyoutQuantity,
            projectedBuyoutRateBps: $item->projectedBuyoutRateBps,
            t1RateBps: $item->t1RateBps,
            t2RateBps: $item->t2RateBps,
            partialReturnRateBps: $item->partialReturnRateBps,
            maturityStatus: $item->maturityStatus,
            resolutionRateBps: $item->resolutionRateBps,
        );
    }

    private static function encodeCursor(string $marketplaceSku): string
    {
        return base64_encode($marketplaceSku);
    }

    private static function decodeCursor(string $cursor): ?BuyoutRateCursor
    {
        $decoded = base64_decode($cursor, true);
        if (
            false === $decoded
            || '' === $decoded
            || 1 !== preg_match('//u', $decoded)
        ) {
            return null;
        }

        return BuyoutRateCursor::fromString($decoded);
    }

    private static function invalid(string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            new ValidationErrorResponse(Response::HTTP_UNPROCESSABLE_ENTITY, $code, $message),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
