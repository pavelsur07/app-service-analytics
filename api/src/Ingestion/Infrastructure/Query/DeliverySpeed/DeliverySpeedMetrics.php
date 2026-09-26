<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DeliverySpeed;

/**
 * Показатели группы отправлений (DeliverySpeedSql::metricsSelect).
 * Длительности — секунды. Медиана, опирающаяся меньше чем на
 * DeliverySpeedSql::MIN_POSTINGS отправлений, не отдаётся: при трёх заказах
 * она шумит, а экран выдал бы шум за вывод.
 */
final readonly class DeliverySpeedMetrics
{
    public function __construct(
        public int $postings,
        public int $arrivedPostings,
        public int $rescanArrivedPostings,
        public int $localArrivedPostings,
        public int $nonlocalArrivedPostings,
        public ?int $medianDeliverySeconds,
        public ?int $p90DeliverySeconds,
        public ?int $medianAssemblySeconds,
        public ?int $medianTransitSeconds,
        public ?int $medianLocalSeconds,
        public ?int $medianNonlocalSeconds,
        public bool $sufficientData,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $arrived = self::int($row['arrived_postings']);
        $local = self::int($row['local_arrived_postings']);
        $nonlocal = self::int($row['nonlocal_arrived_postings']);
        $sufficient = $arrived >= DeliverySpeedSql::MIN_POSTINGS;
        // Медианы этапов опираются на свои выборки, а не на число прибывших.
        $handedOver = self::int($row['handed_over_postings']);
        $arrivedHandedOver = self::int($row['arrived_handed_over_postings']);

        return new self(
            postings: self::int($row['postings']),
            arrivedPostings: $arrived,
            rescanArrivedPostings: self::int($row['rescan_arrived_postings']),
            localArrivedPostings: $local,
            nonlocalArrivedPostings: $nonlocal,
            medianDeliverySeconds: $sufficient ? self::nullableInt($row['median_delivery_seconds']) : null,
            p90DeliverySeconds: $sufficient ? self::nullableInt($row['p90_delivery_seconds']) : null,
            medianAssemblySeconds: $handedOver >= DeliverySpeedSql::MIN_POSTINGS ? self::nullableInt($row['median_assembly_seconds']) : null,
            medianTransitSeconds: $arrivedHandedOver >= DeliverySpeedSql::MIN_POSTINGS ? self::nullableInt($row['median_transit_seconds']) : null,
            medianLocalSeconds: $local >= DeliverySpeedSql::MIN_POSTINGS ? self::nullableInt($row['median_local_seconds']) : null,
            medianNonlocalSeconds: $nonlocal >= DeliverySpeedSql::MIN_POSTINGS ? self::nullableInt($row['median_nonlocal_seconds']) : null,
            sufficientData: $sufficient,
        );
    }

    public static function int(mixed $value): int
    {
        if (!\is_int($value) && !(\is_string($value) && is_numeric($value))) {
            throw new \UnexpectedValueException('Expected an integer in a delivery speed row.');
        }

        return (int) $value;
    }

    public static function nullableInt(mixed $value): ?int
    {
        return null === $value ? null : self::int($value);
    }

    public static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string in a delivery speed row.');
        }

        return $value;
    }

    public static function nullableString(mixed $value): ?string
    {
        return null === $value ? null : self::string($value);
    }
}
