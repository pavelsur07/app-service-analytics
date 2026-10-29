<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\NetForecast as Net;
use App\Tests\Support\Builder\NetQualityObservationBuilder;
use PHPUnit\Framework\TestCase;

final class NetQualitySelectionTest extends TestCase
{
    public function testAnOrderCannotMoveFromCalibrationIntoHoldoutByChangingItsBusinessDate(): void
    {
        $stats = new Net\NetQualitySelectionDiagnostics();
        $cluster = hash('sha256', 'same-company-account-order');
        $stats->observeOrder($cluster, '2027-01-01');
        $this->expectException(\DomainException::class);
        $stats->observeOrder($cluster, '2027-01-02');
    }

    public function testEarliestNullIsNotReplacedByLaterNumericAndShuffleCannotChangeSelection(): void
    {
        $builder = NetQualityObservationBuilder::anObservation();
        $first = $builder->build();
        $later = $builder->with('readAtUtc', '2027-01-05T09:00:00.000000Z')->with('payload', ['model' => ['numerator' => '1', 'denominator' => '1'], 'reason' => null])->build();
        $select = new Net\NetQualitySelection();
        $a = iterator_to_array($select->select([$later, $first, $first], new Net\NetQualityBudget()));
        $b = iterator_to_array($select->select([$first, $later], new Net\NetQualityBudget()));
        self::assertCount(2, $a, 'One age observation and one overall observation, rather than three independent cases.');
        self::assertSame(array_map(static fn (Net\NetQualityObservation $o): array => $o->toArray(), $a), array_map(static fn (Net\NetQualityObservation $o): array => $o->toArray(), $b));
        foreach ($a as $observation) {
            self::assertNull($observation->payload['model']);
            self::assertSame('2027-01-04T09:00:00.000000Z', $observation->readAtUtc);
        }
    }

    public function testRepeatedOriginsNeverInflateOverallSample(): void
    {
        $builder = NetQualityObservationBuilder::anObservation();
        $observations = [$builder->build(), $builder->with('group', 'order3_5')->with('readAtUtc', '2027-01-07T09:00:00.000000Z')->build(), $builder->with('group', 'delivery0_6')->with('readAtUtc', '2027-01-08T09:00:00.000000Z')->build()];
        $result = iterator_to_array((new Net\NetQualitySelection())->select($observations, new Net\NetQualityBudget()));
        self::assertCount(4, $result);
        self::assertCount(1, array_filter($result, static fn (Net\NetQualityObservation $o): bool => 'overall' === $o->group));
    }

    public function testMskCalendarAndDeliverySecondsHaveIndependentAgeBoundaries(): void
    {
        self::assertSame('order3_5', Net\NetQualityObservation::orderGroup('2027-01-01', new \DateTimeImmutable('2027-01-03T21:00:00Z')));
        self::assertSame('delivery0_6', Net\NetQualityObservation::deliveryGroup(new \DateTimeImmutable('2027-01-01T21:01:00Z'), new \DateTimeImmutable('2027-01-08T21:00:59Z')));
        self::assertSame('delivery7_29', Net\NetQualityObservation::deliveryGroup(new \DateTimeImmutable('2027-01-01T21:01:00Z'), new \DateTimeImmutable('2027-01-08T21:01:00Z')));
        self::assertNull(Net\NetQualityObservation::deliveryGroup(new \DateTimeImmutable('2027-01-01T00:00:00Z'), new \DateTimeImmutable('2027-03-02T00:00:00Z')));
    }

    public function testDeliveryBoundaryDoesNotRoundUpTheLastMicrosecondOfDaySix(): void
    {
        self::assertSame('delivery0_6', Net\NetQualityObservation::deliveryGroup(new \DateTimeImmutable('2027-01-01T00:00:00.000001Z'), new \DateTimeImmutable('2027-01-08T00:00:00.000000Z')));
    }
}
