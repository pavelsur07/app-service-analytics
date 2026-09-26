<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\Localization\BuildLocalizationReportAction;
use App\Ingestion\Infrastructure\Query\Localization\LocalizationClusterRow;
use App\Ingestion\Infrastructure\Query\Localization\LocalizationMetrics;
use App\Ingestion\Infrastructure\Query\Localization\LocalizationSkuCursor;
use App\Ingestion\Infrastructure\Query\Localization\LocalizationSkuQuery;
use App\Ingestion\Infrastructure\Query\Localization\LocalizationSkuRow;
use App\Ingestion\Infrastructure\Query\Localization\LocalizationSourceCluster;
use App\Ingestion\Infrastructure\Query\Localization\LocalizationSql;
use App\Ingestion\Ui\Response\Localization\LocalizationClusterResponse;
use App\Ingestion\Ui\Response\Localization\LocalizationDefinitionsResponse;
use App\Ingestion\Ui\Response\Localization\LocalizationMetricsResponse;
use App\Ingestion\Ui\Response\Localization\LocalizationReportResponse;
use App\Ingestion\Ui\Response\Localization\LocalizationSkuResponse;
use App\Ingestion\Ui\Response\Localization\LocalizationSourceClusterResponse;
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
    '/api/companies/{companyId}/localization',
    name: 'ingestion_localization_report',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ShowLocalizationReportController
{
    private const int DEFAULT_DAYS = 30;
    private const array ALLOWED_DAYS = [30, 90];
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(private readonly BuildLocalizationReportAction $buildReport)
    {
    }

    #[OA\Parameter(name: 'days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_DAYS, enum: self::ALLOWED_DAYS))]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: LocalizationSkuQuery::DEFAULT_LIMIT, minimum: 1, maximum: LocalizationSkuQuery::MAX_LIMIT))]
    #[OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Локализация продаж Ozon FBO по кластерам', content: new Model(type: LocalizationReportResponse::class))]
    #[OA\Response(response: 403, description: 'Пользователь не состоит в этой компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Некорректные параметры отчёта', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        $days = QueryParameter::int($request, 'days', self::DEFAULT_DAYS);
        if (null === $days || !\in_array($days, self::ALLOWED_DAYS, true)) {
            return self::invalid('invalid_days', 'days must be one of: 30, 90.');
        }

        $limit = QueryParameter::int($request, 'limit', LocalizationSkuQuery::DEFAULT_LIMIT);
        if (null === $limit || $limit < 1 || $limit > LocalizationSkuQuery::MAX_LIMIT) {
            return self::invalid('invalid_limit', 'limit must be an integer between 1 and 200.');
        }

        $cursor = null;
        if ($request->query->has('cursor')) {
            $cursor = LocalizationSkuCursor::decode((string) $request->query->get('cursor'));
            if (null === $cursor || $cursor->days !== $days) {
                return self::invalid('invalid_cursor', 'cursor is malformed.');
            }
        }

        $to = (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->setTime(0, 0);
        $from = $to->modify('-'.($days - 1).' days');
        $report = ($this->buildReport)($companyId, $from, $to, $days, $limit, $cursor);

        return new JsonResponse(new LocalizationReportResponse(
            from: $from->format('Y-m-d'),
            to: $to->format('Y-m-d'),
            definitions: new LocalizationDefinitionsResponse(
                minQuantity: LocalizationSql::MIN_QUANTITY,
                rounding: LocalizationSql::ROUNDING,
                forwardFeeTypeIds: LocalizationSql::FORWARD_FEE_TYPES,
                reverseFeeTypeIds: LocalizationSql::REVERSE_FEE_TYPES,
            ),
            summary: self::metrics($report->summary),
            clusters: array_map(self::cluster(...), $report->clusters),
            clustersTruncated: $report->clustersTruncated,
            items: array_map(self::sku(...), $report->skus),
            nextCursor: $report->nextCursor?->encode(),
        ));
    }

    private static function metrics(LocalizationMetrics $metrics): LocalizationMetricsResponse
    {
        return new LocalizationMetricsResponse(
            quantity: $metrics->quantity,
            clusteredQuantity: $metrics->clusteredQuantity,
            localQuantity: $metrics->localQuantity,
            nonlocalQuantity: $metrics->nonlocalQuantity,
            localShareBps: $metrics->localShareBps,
            chargedQuantity: $metrics->chargedQuantity,
            chargedShareBps: $metrics->chargedShareBps,
            localForwardCostPerUnitMinor: $metrics->localForwardCostPerUnitMinor,
            nonlocalForwardCostPerUnitMinor: $metrics->nonlocalForwardCostPerUnitMinor,
            reverseCostMinor: $metrics->reverseCostMinor,
            currency: $metrics->currency,
            sufficientData: $metrics->sufficientData,
        );
    }

    private static function cluster(LocalizationClusterRow $row): LocalizationClusterResponse
    {
        return new LocalizationClusterResponse(
            clusterTo: $row->clusterTo,
            metrics: self::metrics($row->metrics),
            topSources: array_map(
                static fn (LocalizationSourceCluster $source): LocalizationSourceClusterResponse => new LocalizationSourceClusterResponse(
                    cluster: $source->cluster,
                    quantity: $source->quantity,
                    shareBps: $source->shareBps,
                ),
                $row->topSources,
            ),
        );
    }

    private static function sku(LocalizationSkuRow $row): LocalizationSkuResponse
    {
        return new LocalizationSkuResponse(
            marketplaceSku: $row->marketplaceSku,
            offerId: $row->offerId,
            name: $row->name,
            clusterTo: $row->clusterTo,
            mainSourceCluster: $row->mainSourceCluster,
            metrics: self::metrics($row->metrics),
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
