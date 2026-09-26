<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\DeliverySpeed\BuildDeliverySpeedReportAction;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedBucketRow;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedClusterRow;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedMetrics;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedRouteRow;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedSkuCursor;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedSkuQuery;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedSkuRow;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedSql;
use App\Ingestion\Ui\Response\DeliverySpeed\DeliverySpeedBucketResponse;
use App\Ingestion\Ui\Response\DeliverySpeed\DeliverySpeedClusterResponse;
use App\Ingestion\Ui\Response\DeliverySpeed\DeliverySpeedDefinitionsResponse;
use App\Ingestion\Ui\Response\DeliverySpeed\DeliverySpeedMetricsResponse;
use App\Ingestion\Ui\Response\DeliverySpeed\DeliverySpeedReportResponse;
use App\Ingestion\Ui\Response\DeliverySpeed\DeliverySpeedRouteResponse;
use App\Ingestion\Ui\Response\DeliverySpeed\DeliverySpeedSkuResponse;
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
    '/api/companies/{companyId}/delivery-speed',
    name: 'ingestion_delivery_speed_report',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ShowDeliverySpeedReportController
{
    private const int DEFAULT_DAYS = 30;
    private const array ALLOWED_DAYS = [30, 90];
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(private readonly BuildDeliverySpeedReportAction $buildReport)
    {
    }

    #[OA\Parameter(name: 'days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_DAYS, enum: self::ALLOWED_DAYS))]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: DeliverySpeedSkuQuery::DEFAULT_LIMIT, minimum: 1, maximum: DeliverySpeedSkuQuery::MAX_LIMIT))]
    #[OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Скорость доставки Ozon FBO', content: new Model(type: DeliverySpeedReportResponse::class))]
    #[OA\Response(response: 403, description: 'Пользователь не состоит в этой компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Некорректные параметры отчёта', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        $days = QueryParameter::int($request, 'days', self::DEFAULT_DAYS);
        if (null === $days || !\in_array($days, self::ALLOWED_DAYS, true)) {
            return self::invalid('invalid_days', 'days must be one of: 30, 90.');
        }

        $limit = QueryParameter::int($request, 'limit', DeliverySpeedSkuQuery::DEFAULT_LIMIT);
        if (null === $limit || $limit < 1 || $limit > DeliverySpeedSkuQuery::MAX_LIMIT) {
            return self::invalid('invalid_limit', 'limit must be an integer between 1 and 200.');
        }

        // Окно заканчивается за MATURITY_LAG_DAYS до сегодня: свежие заказы
        // ещё доезжают, и медиана была бы занижена.
        $today = (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->setTime(0, 0);
        $maturedTo = $today->modify('-'.DeliverySpeedSql::MATURITY_LAG_DAYS.' days');
        $to = $maturedTo;
        $cursor = null;
        if ($request->query->has('cursor')) {
            $cursor = DeliverySpeedSkuCursor::decode((string) $request->query->get('cursor'));
            // Курсор с окном этих или прошлых суток — после полуночи страницу
            // продолжают в том же окне. Иные окна — не наш курсор.
            if (
                null === $cursor
                || $cursor->days !== $days
                || $cursor->to > $maturedTo
                || $cursor->to < $maturedTo->modify('-1 day')
            ) {
                return self::invalid('invalid_cursor', 'cursor is malformed.');
            }
            $to = $cursor->to;
        }
        $from = $to->modify('-'.($days - 1).' days');
        $report = ($this->buildReport)($companyId, $from, $to, $days, $limit, $cursor);

        return new JsonResponse(new DeliverySpeedReportResponse(
            from: $from->format('Y-m-d'),
            to: $to->format('Y-m-d'),
            definitions: new DeliverySpeedDefinitionsResponse(
                startEvent: DeliverySpeedSql::START_EVENT,
                arrivalEvent: DeliverySpeedSql::ARRIVAL_EVENT,
                estimate: DeliverySpeedSql::ESTIMATE,
                tickStepSeconds: DeliverySpeedSql::TICK_STEP_SECONDS,
                rescanStepSeconds: DeliverySpeedSql::RESCAN_STEP_SECONDS,
                tickWindowDays: DeliverySpeedSql::TICK_WINDOW_DAYS,
                liveObservationMaxLagHours: DeliverySpeedSql::LIVE_OBSERVATION_MAX_LAG_HOURS,
                maturityLagDays: DeliverySpeedSql::MATURITY_LAG_DAYS,
                minPostings: DeliverySpeedSql::MIN_POSTINGS,
            ),
            periodPostings: $report->periodPostings,
            summary: self::metrics($report->summary),
            clusters: array_map(
                static fn (DeliverySpeedClusterRow $row): DeliverySpeedClusterResponse => new DeliverySpeedClusterResponse(
                    clusterTo: $row->clusterTo,
                    metrics: self::metrics($row->metrics),
                    lostHours: $row->lostHours,
                ),
                $report->clusters,
            ),
            clustersTruncated: $report->clustersTruncated,
            routes: array_map(
                static fn (DeliverySpeedRouteRow $row): DeliverySpeedRouteResponse => new DeliverySpeedRouteResponse(
                    clusterFrom: $row->clusterFrom,
                    clusterTo: $row->clusterTo,
                    local: $row->local,
                    metrics: self::metrics($row->metrics),
                ),
                $report->routes,
            ),
            routesTruncated: $report->routesTruncated,
            items: array_map(
                static fn (DeliverySpeedSkuRow $row): DeliverySpeedSkuResponse => new DeliverySpeedSkuResponse(
                    marketplaceSku: $row->marketplaceSku,
                    offerId: $row->offerId,
                    name: $row->name,
                    clusterTo: $row->clusterTo,
                    quantity: $row->quantity,
                    nonlocalQuantity: $row->nonlocalQuantity,
                    nonlocalArrivedPostings: $row->nonlocalArrivedPostings,
                    clusterMedianLocalSeconds: $row->clusterMedianLocalSeconds,
                    clusterMedianNonlocalSeconds: $row->clusterMedianNonlocalSeconds,
                    lostHours: $row->lostHours,
                ),
                $report->skus,
            ),
            nextCursor: $report->nextCursor?->encode(),
            buyoutBySpeed: array_map(
                static fn (DeliverySpeedBucketRow $row): DeliverySpeedBucketResponse => new DeliverySpeedBucketResponse(
                    minDays: $row->minDays,
                    maxDays: $row->maxDays,
                    postings: $row->postings,
                    deliveredQuantity: $row->deliveredQuantity,
                    resolvedQuantity: $row->resolvedQuantity,
                    buyoutRateBps: $row->buyoutRateBps,
                ),
                $report->buyoutBySpeed,
            ),
        ));
    }

    private static function metrics(DeliverySpeedMetrics $metrics): DeliverySpeedMetricsResponse
    {
        return new DeliverySpeedMetricsResponse(
            postings: $metrics->postings,
            arrivedPostings: $metrics->arrivedPostings,
            rescanArrivedPostings: $metrics->rescanArrivedPostings,
            localArrivedPostings: $metrics->localArrivedPostings,
            nonlocalArrivedPostings: $metrics->nonlocalArrivedPostings,
            medianDeliverySeconds: $metrics->medianDeliverySeconds,
            p90DeliverySeconds: $metrics->p90DeliverySeconds,
            medianAssemblySeconds: $metrics->medianAssemblySeconds,
            medianTransitSeconds: $metrics->medianTransitSeconds,
            medianLocalSeconds: $metrics->medianLocalSeconds,
            medianNonlocalSeconds: $metrics->medianNonlocalSeconds,
            sufficientData: $metrics->sufficientData,
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
