<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\BuildBuyoutDailySeriesAction;
use App\Ingestion\Infrastructure\Query\BuyoutDailyRow;
use App\Ingestion\Ui\Response\BuyoutDailyPointResponse;
use App\Ingestion\Ui\Response\BuyoutDailyResponse;
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
    '/api/companies/{companyId}/buyout-rate/{sku}/daily',
    name: 'ingestion_buyout_rate_daily',
    requirements: ['companyId' => Requirement::UUID, 'sku' => '[^/]+'],
    methods: ['GET'],
)]
final class ShowSkuBuyoutDailyController
{
    private const int DEFAULT_DAYS = 30;
    private const array ALLOWED_DAYS = [7, 30, 90];
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(private readonly BuildBuyoutDailySeriesAction $buildSeries)
    {
    }

    #[OA\Parameter(name: 'days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 30, enum: self::ALLOWED_DAYS))]
    #[OA\Response(response: 200, description: 'Дневной ряд actual/projected одной SKU', content: new Model(type: BuyoutDailyResponse::class))]
    #[OA\Response(response: 403, description: 'Пользователь не состоит в этой компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Некорректные days или SKU', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, string $sku, Request $request): JsonResponse
    {
        $days = QueryParameter::int($request, 'days', self::DEFAULT_DAYS);
        if (null === $days || !\in_array($days, self::ALLOWED_DAYS, true)) {
            return self::invalid('invalid_days', 'days must be one of: 7, 30, 90.');
        }
        if ('' === $sku || 1 !== preg_match('//u', $sku) || mb_strlen($sku, 'UTF-8') > 64 || 1 === preg_match('/[\x00-\x1F\x7F]/u', $sku)) {
            return self::invalid('invalid_sku', 'sku must be a non-empty UTF-8 string up to 64 characters.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
        $to = $now->setTime(0, 0);
        $from = $to->modify('-'.($days - 1).' days');
        $rows = ($this->buildSeries)($companyId, $sku, $from, $to, $now);

        return new JsonResponse(new BuyoutDailyResponse(
            marketplaceSku: $sku,
            series: array_map(self::point(...), $rows),
        ));
    }

    private static function point(BuyoutDailyRow $row): BuyoutDailyPointResponse
    {
        return new BuyoutDailyPointResponse(
            date: $row->date,
            actualBuyoutRateBps: $row->actualBuyoutRateBps,
            projectedBuyoutRateBps: $row->projectedBuyoutRateBps,
            resolutionRateBps: $row->resolutionRateBps,
            orderedQuantity: $row->orderedQuantity,
            resolvedQuantity: $row->resolvedQuantity,
            projectedBuyoutQuantity: $row->projectedBuyoutQuantity,
        );
    }

    private static function invalid(string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            new ValidationErrorResponse(Response::HTTP_UNPROCESSABLE_ENTITY, $code, $message),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
