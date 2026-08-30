<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** Полный aggregate периода; не зависит от SKU-страницы. */
final readonly class BuyoutForecastSummaryQuery
{
    public function __construct(
        private Connection $connection,
        private BuyoutForecastQuery $forecast,
    ) {
    }

    public function build(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $asOf,
    ): QueryBuilder {
        $base = $this->forecast->build($companyId, $from, $to, $asOf, 0, null);

        return $this->connection->createQueryBuilder()
            ->select(
                'COALESCE(SUM(ordered_quantity), 0)::bigint AS ordered_quantity',
                'COALESCE(SUM(resolved_quantity), 0)::bigint AS resolved_quantity',
                'CASE WHEN COUNT(*) FILTER (WHERE projected_buyout_quantity_exact IS NULL) > 0 THEN NULL ELSE ROUND(SUM(projected_buyout_quantity_exact))::int END AS projected_buyout_quantity',
                'CASE WHEN COUNT(*) = 0 OR COUNT(*) FILTER (WHERE projected_buyout_quantity_exact IS NULL) > 0 OR SUM(projected_eligible_quantity_exact) = 0 THEN NULL ELSE ROUND(10000::numeric * SUM(projected_buyout_quantity_exact) / SUM(projected_eligible_quantity_exact))::int END AS projected_buyout_rate_bps',
                'CASE WHEN COALESCE(SUM(ordered_quantity), 0) = 0 THEN NULL ELSE ROUND(10000::numeric * SUM(resolved_quantity) / SUM(ordered_quantity))::int END AS resolution_rate_bps',
            )
            ->from('('.$base->getSQL().')', 'forecast_summary')
            ->setParameters($base->getParameters(), $base->getParameterTypes());
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): BuyoutForecastSummaryRow
    {
        return new BuyoutForecastSummaryRow(
            orderedQuantity: self::integer($row['ordered_quantity'] ?? null),
            resolvedQuantity: self::integer($row['resolved_quantity'] ?? null),
            projectedBuyoutQuantity: self::nullableInteger($row['projected_buyout_quantity'] ?? null),
            projectedBuyoutRateBps: self::nullableInteger($row['projected_buyout_rate_bps'] ?? null),
            resolutionRateBps: self::nullableInteger($row['resolution_rate_bps'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row forecast row with pre-pagination window totals
     */
    public static function mapWindowRow(array $row): BuyoutForecastSummaryRow
    {
        return self::mapRow([
            'ordered_quantity' => $row['summary_ordered_quantity'] ?? null,
            'resolved_quantity' => $row['summary_resolved_quantity'] ?? null,
            'projected_buyout_quantity' => $row['summary_projected_buyout_quantity'] ?? null,
            'projected_buyout_rate_bps' => $row['summary_projected_buyout_rate_bps'] ?? null,
            'resolution_rate_bps' => $row['summary_resolution_rate_bps'] ?? null,
        ]);
    }

    private static function integer(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('Expected integer in buyout forecast summary row.');
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return null === $value ? null : self::integer($value);
    }
}
