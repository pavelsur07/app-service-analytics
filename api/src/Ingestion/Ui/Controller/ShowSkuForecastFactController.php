<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\Buyout\BuildBuyoutDailySeriesAction;
use App\Ingestion\Infrastructure\Query\Buyout\BuyoutDailyRow;
use App\Ingestion\Ui\Response\SkuForecastFactDayResponse;
use App\Ingestion\Ui\Response\SkuForecastFactResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(
    '/api/companies/{companyId}/sku-forecast-fact',
    name: 'ingestion_sku_forecast_fact',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ShowSkuForecastFactController
{
    public function __construct(private readonly BuildBuyoutDailySeriesAction $buildSeries)
    {
    }

    #[OA\Parameter(name: 'sku', in: 'query', required: true, schema: new OA\Schema(type: 'string', maxLength: 64))]
    #[OA\Parameter(name: 'month', in: 'query', required: false, schema: new OA\Schema(type: 'string', pattern: '^\\d{4}-(0[1-9]|1[0-2])$'))]
    #[OA\Response(response: 200, description: 'Прогноз и факт по SKU за месяц заказа', content: new Model(type: SkuForecastFactResponse::class))]
    #[OA\Response(response: 403, description: 'Пользователь не состоит в компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Некорректный SKU или месяц', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        $params = $request->query->all();
        $sku = $params['sku'] ?? null;
        if (!\is_string($sku) || '' === $sku || 1 !== preg_match('//u', $sku) || mb_strlen($sku, 'UTF-8') > 64 || 1 === preg_match('/[\x00-\x1F\x7F]/u', $sku)) {
            return self::invalid('invalid_sku', 'sku must be a non-empty UTF-8 string up to 64 characters.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow'));
        $currentMonth = $now->format('Y-m');
        $month = $params['month'] ?? $currentMonth;
        if (!\is_string($month) || 1 !== preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || $month < '2020-01' || $month > $currentMonth) {
            return self::invalid('invalid_month', 'month must be a past or current YYYY-MM from 2020-01.');
        }

        $from = new \DateTimeImmutable($month.'-01', new \DateTimeZone('Europe/Moscow'));
        $end = $from->modify('last day of this month');
        $to = $end > $now ? $now : $end;
        $rows = ($this->buildSeries)($companyId, $sku, $from, $to, $now, withMoney: true);
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row->date] = $row;
        }

        $days = [];
        for ($day = $to; $day >= $from; $day = $day->modify('-1 day')) {
            $date = $day->format('Y-m-d');
            $days[] = self::day($date, $byDate[$date] ?? null);
        }

        return new JsonResponse(new SkuForecastFactResponse($sku, $month, 'RUB', $days));
    }

    private static function day(string $date, ?BuyoutDailyRow $row): SkuForecastFactDayResponse
    {
        if (null === $row) {
            return new SkuForecastFactDayResponse($date, 0, 0, null, null, 0);
        }

        if (null === $row->orderedAmountMinor || null === $row->actualRevenueMinor) {
            throw new \UnexpectedValueException('Monetary daily row is incomplete.');
        }

        return new SkuForecastFactDayResponse(
            $date,
            $row->orderedAmountMinor,
            $row->orderedQuantity,
            $row->projectedBuyoutRateBps,
            $row->forecastRevenueMinor,
            $row->actualRevenueMinor,
        );
    }

    private static function invalid(string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse(Response::HTTP_UNPROCESSABLE_ENTITY, $code, $message), Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
