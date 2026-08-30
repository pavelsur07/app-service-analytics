<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Ingestion\Infrastructure\Query\BuyoutForecastQuery;
use App\Ingestion\Infrastructure\Query\BuyoutForecastRow;
use App\Ingestion\Infrastructure\Query\BuyoutForecastSummaryQuery;
use App\Ingestion\Infrastructure\Query\BuyoutRateQuery;
use App\Ingestion\Infrastructure\Query\BuyoutRateRow;
use Doctrine\DBAL\Connection;

/** Выполняет bounded aggregate и собирает keyset-страницу отчёта. */
final readonly class BuildBuyoutRateReportAction
{
    public function __construct(
        private Connection $connection,
        private BuyoutRateQuery $query,
        private BuyoutForecastQuery $forecast,
        private BuyoutForecastSummaryQuery $summary,
    ) {
    }

    public function __invoke(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $asOf,
        int $limit,
        ?string $cursor,
    ): BuyoutRateReport {
        // PostgreSQL badly expands three copies of the live outcome view when
        // they are nested into one giant JSON statement. A repeatable-read,
        // read-only transaction keeps the same report snapshot without that
        // quadratic planner/executor cost.
        $readSnapshot = function (Connection $connection) use ($companyId, $from, $to, $asOf, $limit, $cursor): BuyoutRateReport {
            $rates = $this->query->build($companyId, $from, $to, $asOf, $limit, $cursor);
            $rateRows = $connection->fetchAllAssociative(
                $rates->getSQL(),
                $rates->getParameters(),
                $rates->getParameterTypes(),
            );
            $mapped = array_map(BuyoutRateQuery::mapRow(...), $rateRows);

            $forecast = $this->forecast->build($companyId, $from, $to, $asOf, $limit, $cursor);
            $forecastRows = $connection->fetchAllAssociative(
                $forecast->getSQL(),
                $forecast->getParameters(),
                $forecast->getParameterTypes(),
            );
            $forecastBySku = [];
            foreach (array_map(BuyoutForecastQuery::mapRow(...), $forecastRows) as $forecastRow) {
                $forecastBySku[$forecastRow->marketplaceSku] = $forecastRow;
            }

            $summary = [] === $forecastRows
                ? $this->emptyOrCursorSummary($connection, $companyId, $from, $to, $asOf)
                : BuyoutForecastSummaryQuery::mapWindowRow($forecastRows[0]);
            $hasNext = \count($mapped) > $limit;
            if ($hasNext) {
                array_pop($mapped);
            }

            return new BuyoutRateReport(
                summary: new BuyoutRateSummary(
                    orderedQuantity: $summary->orderedQuantity,
                    resolvedQuantity: $summary->resolvedQuantity,
                    projectedBuyoutQuantity: $summary->projectedBuyoutQuantity,
                    projectedBuyoutRateBps: $summary->projectedBuyoutRateBps,
                    resolutionRateBps: $summary->resolutionRateBps,
                ),
                items: array_map(
                    static fn (BuyoutRateRow $row): BuyoutRateSku => self::toSku($row, $forecastBySku[$row->marketplaceSku] ?? null),
                    $mapped,
                ),
                nextCursor: $hasNext && [] !== $mapped ? $mapped[array_key_last($mapped)]->marketplaceSku : null,
            );
        };

        // Интеграционные тесты и допустимые внешние application workflows
        // могут уже держать транзакцию. Уровень её изоляции определяет
        // владелец; SET TRANSACTION после выполненных им запросов PostgreSQL
        // закономерно запрещает.
        $nativeConnection = $this->connection->getNativeConnection();
        if (
            $this->connection->isTransactionActive()
            || ($nativeConnection instanceof \PDO && $nativeConnection->inTransaction())
        ) {
            // Expanded outcome/maturity CTEs can cross PostgreSQL's JIT cost
            // threshold even for a small tenant window. Compilation then
            // dominates the request by tens of seconds, while these bounded
            // aggregates execute faster without JIT.
            $this->connection->executeStatement('SET LOCAL jit = off');
            $isolation = $this->connection->fetchOne('SHOW transaction_isolation');
            if (!\is_string($isolation) || !\in_array(strtolower($isolation), ['repeatable read', 'serializable'], true)) {
                return $this->readSingleStatement($companyId, $from, $to, $asOf, $limit, $cursor);
            }

            return $readSnapshot($this->connection);
        }

        return $this->connection->transactional(
            static function (Connection $connection) use ($readSnapshot): BuyoutRateReport {
                $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                $connection->executeStatement('SET LOCAL jit = off');

                return $readSnapshot($connection);
            },
        );
    }

    /**
     * An outer READ COMMITTED transaction cannot promise one snapshot across
     * statements. Keep the consistency guarantee there with a single SQL
     * statement; this branch is deliberately not used by the HTTP endpoint.
     */
    private function readSingleStatement(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $asOf,
        int $limit,
        ?string $cursor,
    ): BuyoutRateReport {
        $rates = $this->query->build($companyId, $from, $to, $asOf, $limit, $cursor);
        $forecast = $this->forecast->build($companyId, $from, $to, $asOf, $limit, $cursor);
        $summary = $this->summary->build($companyId, $from, $to, $asOf);
        $sql = <<<SQL
            SELECT
                COALESCE((
                    SELECT jsonb_agg(to_jsonb(rate_row) ORDER BY rate_row.marketplace_sku)
                    FROM ({$rates->getSQL()}) rate_row
                ), '[]'::jsonb)::text AS rates,
                COALESCE((
                    SELECT jsonb_agg(to_jsonb(forecast_row) ORDER BY forecast_row.marketplace_sku)
                    FROM ({$forecast->getSQL()}) forecast_row
                ), '[]'::jsonb)::text AS forecast,
                (SELECT to_jsonb(summary_row) FROM ({$summary->getSQL()}) summary_row)::text AS summary
            SQL;
        $result = $this->connection->fetchAssociative(
            $sql,
            array_replace($rates->getParameters(), $forecast->getParameters(), $summary->getParameters()),
            array_replace($rates->getParameterTypes(), $forecast->getParameterTypes(), $summary->getParameterTypes()),
        );
        if (false === $result) {
            throw new \RuntimeException('Buyout report query returned no row.');
        }

        $mapped = array_map(BuyoutRateQuery::mapRow(...), self::decodeRows($result['rates'] ?? null, 'rates'));
        $forecastBySku = [];
        foreach (array_map(BuyoutForecastQuery::mapRow(...), self::decodeRows($result['forecast'] ?? null, 'forecast')) as $forecastRow) {
            $forecastBySku[$forecastRow->marketplaceSku] = $forecastRow;
        }
        $summaryRows = self::decodeRows('['.self::json($result['summary'] ?? null, 'summary').']', 'summary');
        $summaryRow = $summaryRows[0] ?? throw new \RuntimeException('Buyout summary aggregate returned no row.');
        $summaryResult = BuyoutForecastSummaryQuery::mapRow($summaryRow);
        $hasNext = \count($mapped) > $limit;
        if ($hasNext) {
            array_pop($mapped);
        }

        return new BuyoutRateReport(
            summary: new BuyoutRateSummary(
                orderedQuantity: $summaryResult->orderedQuantity,
                resolvedQuantity: $summaryResult->resolvedQuantity,
                projectedBuyoutQuantity: $summaryResult->projectedBuyoutQuantity,
                projectedBuyoutRateBps: $summaryResult->projectedBuyoutRateBps,
                resolutionRateBps: $summaryResult->resolutionRateBps,
            ),
            items: array_map(
                static fn (BuyoutRateRow $row): BuyoutRateSku => self::toSku($row, $forecastBySku[$row->marketplaceSku] ?? null),
                $mapped,
            ),
            nextCursor: $hasNext && [] !== $mapped ? $mapped[array_key_last($mapped)]->marketplaceSku : null,
        );
    }

    private function emptyOrCursorSummary(
        Connection $connection,
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $asOf,
    ): \App\Ingestion\Infrastructure\Query\BuyoutForecastSummaryRow {
        $summaryQuery = $this->summary->build($companyId, $from, $to, $asOf);
        $summaryRow = $connection->fetchAssociative(
            $summaryQuery->getSQL(),
            $summaryQuery->getParameters(),
            $summaryQuery->getParameterTypes(),
        );
        if (false === $summaryRow) {
            throw new \RuntimeException('Buyout summary aggregate returned no row.');
        }

        return BuyoutForecastSummaryQuery::mapRow($summaryRow);
    }

    /** @return list<array<string, mixed>> */
    private static function decodeRows(mixed $jsonValue, string $field): array
    {
        $decoded = json_decode(self::json($jsonValue, $field), true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !array_is_list($decoded)) {
            throw new \UnexpectedValueException("Expected JSON row list in buyout report {$field}.");
        }
        $rows = [];
        foreach ($decoded as $row) {
            if (!\is_array($row)) {
                throw new \UnexpectedValueException("Expected JSON object in buyout report {$field}.");
            }
            $normalized = [];
            foreach ($row as $key => $value) {
                if (!\is_string($key)) {
                    throw new \UnexpectedValueException("Expected JSON object keys in buyout report {$field}.");
                }
                $normalized[$key] = $value;
            }
            $rows[] = $normalized;
        }

        return $rows;
    }

    private static function json(mixed $value, string $field): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException("Expected JSON string in buyout report {$field}.");
        }

        return $value;
    }

    private static function toSku(BuyoutRateRow $row, ?BuyoutForecastRow $forecast): BuyoutRateSku
    {
        return new BuyoutRateSku(
            marketplaceSku: $row->marketplaceSku,
            offerId: $row->offerId,
            name: $row->name,
            orderedQuantity: $row->orderedQuantity,
            resolvedQuantity: null === $forecast ? 0 : $forecast->resolvedQuantity,
            projectedBuyoutQuantity: $forecast?->projectedBuyoutQuantity,
            projectedBuyoutRateBps: $forecast?->projectedBuyoutRateBps,
            t1Quantity: $row->t1Quantity,
            deliveredQuantity: $row->deliveredQuantity,
            t2Quantity: $row->t2Quantity,
            partialReturnQuantity: $row->partialReturnQuantity,
            clientReturnQuantity: $row->clientReturnQuantity,
            unresolvedQuantity: $row->unresolvedQuantity,
            conversionRateBps: $row->conversionRateBps,
            actualBuyoutRateBps: $row->actualBuyoutRateBps,
            resolutionRateBps: $row->resolutionRateBps,
            t1RateBps: $row->t1RateBps,
            t2RateBps: $row->t2RateBps,
            partialReturnRateBps: $row->partialReturnRateBps,
            maturityStatus: $row->maturityStatus,
        );
    }
}
