<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Domain\ValueObject\NetForwardReturnDelta;
use App\Ingestion\Domain\ValueObject\NetForwardReturnRevisionPolicy;
use App\Ingestion\Domain\ValueObject\NetForwardTerminalCoverage;
use App\Ingestion\Domain\ValueObject\NetForwardTruth;
use PHPUnit\Framework\TestCase;

final class NetForwardTruthTest extends TestCase
{
    public function testOpenDeliveryOriginDeterminesReadinessAndHorizon(): void
    {
        $t = new \DateTimeImmutable('2026-01-01T00:00:00.123456Z');
        $origin = $t->modify('+20 days');
        $returns = [new NetForwardReturnDelta(2, $t->modify('+75 days')), new NetForwardReturnDelta(3, $t->modify('+80 days'))];
        $pending = NetForwardTruth::delivered(10, $origin, $t, $t->modify('+67 days'), [], true, true);
        self::assertSame('pending', $pending->state);
        self::assertNull($pending->quantity);
        $ready = NetForwardTruth::delivered(10, $origin, $t, $t->modify('+87 days'), $returns, true, true);
        self::assertSame('ready', $ready->state);
        self::assertSame(8, $ready->quantity);
        self::assertSame($t->modify('+87 days')->format('U.u'), $ready->readyAt?->format('U.u'));
    }

    public function testKnownReturnIsNotDeductedAgainAndLateVisibilityIsNotHistoricalProof(): void
    {
        $origin = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $input = $origin->modify('+30 days');
        $known = new NetForwardReturnDelta(20, $origin->modify('+20 days'), true);
        $new = new NetForwardReturnDelta(5, $origin->modify('+40 days'));
        self::assertSame(75, NetForwardTruth::delivered(80, $origin, $input, $origin->modify('+67 days'), [$known, $new], false, true)->quantity);
        self::assertSame('ineligible', NetForwardTruth::delivered(80, $origin, $input, $origin->modify('+67 days'), [new NetForwardReturnDelta(1, $origin->modify('+25 days'))], false, true)->state);
    }

    public function testCoverageAndNegativeRevisionsNeverCreateZero(): void
    {
        $origin = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        self::assertSame('pending', NetForwardTruth::delivered(4, $origin, $origin->modify('+1 day'), $origin->modify('+90 days'), [], false, false)->state);
        self::assertSame('ineligible', NetForwardTruth::delivered(4, $origin, $origin->modify('+1 day'), $origin->modify('+90 days'), [new NetForwardReturnDelta(-1, $origin->modify('+2 days'))], false, true)->state);
        self::assertNull(NetForwardTruth::terminal($origin, $origin->modify('+6 days'), true)->quantity);
        self::assertSame(0, NetForwardTruth::terminal($origin, $origin->modify('+7 days'), true)->quantity);
    }

    public function testTerminalCoverageRequiresOneDayReceiptForEveryDate(): void
    {
        $terminal = new \DateTimeImmutable('2026-01-01T09:00:00Z');
        $from = $terminal->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0);
        $receipts = [];
        for ($day = $from; $day <= $from->modify('+7 days'); $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $receipts[] = ['report_type' => 'ozon_returns_list', 'date_from' => $date, 'date_to' => $date, 'marked_at' => $day->modify('+1 day +1 hour')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), 'status' => 'completed', 'capture_origin' => 'live_polling', 'capture_version' => 'accepted_state_v1'];
        }
        self::assertTrue(NetForwardTerminalCoverage::isComplete($terminal, $receipts));
        array_pop($receipts);
        $receipts[] = ['report_type' => 'ozon_returns_list', 'date_from' => $from->format('Y-m-d'), 'date_to' => $from->modify('+7 days')->format('Y-m-d'), 'marked_at' => $from->modify('+8 days +1 hour')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), 'status' => 'completed', 'capture_origin' => 'live_polling', 'capture_version' => 'accepted_state_v1'];
        self::assertFalse(NetForwardTerminalCoverage::isComplete($terminal, $receipts));
    }

    public function testKnownCancellationMayTransitionToClientReturnForTruth(): void
    {
        self::assertTrue(NetForwardReturnRevisionPolicy::isProven('Cancellation', 'ClientReturn', true));
        self::assertFalse(NetForwardReturnRevisionPolicy::isProven('ClientReturn', 'Cancellation', true));
        self::assertFalse(NetForwardReturnRevisionPolicy::isProven('Cancellation', 'unknown', true));
        self::assertFalse(NetForwardReturnRevisionPolicy::isProven('Cancellation', null, true));
        self::assertFalse(NetForwardReturnRevisionPolicy::isProven('Cancellation', 'ClientReturn', false));

        $input = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $origin = $input->modify('-1 day');
        $knownCancellation = new NetForwardReturnDelta(0, $input->modify('-1 hour'), true);
        $firstClientReturn = new NetForwardReturnDelta(2, $input->modify('+20 days'), false, NetForwardReturnRevisionPolicy::isProven('Cancellation', 'ClientReturn', true));
        $truth = NetForwardTruth::delivered(10, $origin, $input, $input->modify('+90 days'), [$knownCancellation, $firstClientReturn], false, true);
        self::assertSame('ready', $truth->state);
        self::assertSame(8, $truth->quantity);

        $deliveryOrigin = $input->modify('+20 days');
        $preDeliveryCancellation = new NetForwardReturnDelta(0, $input->modify('+5 days'), false, true, 'Cancellation');
        $postDeliveryClientReturn = new NetForwardReturnDelta(2, $input->modify('+30 days'), false, NetForwardReturnRevisionPolicy::isProven('Cancellation', 'ClientReturn', true), 'ClientReturn');
        $lateTruth = NetForwardTruth::delivered(10, $deliveryOrigin, $input, $input->modify('+90 days'), [$preDeliveryCancellation, $postDeliveryClientReturn], true, true);
        self::assertSame('ready', $lateTruth->state);
        self::assertSame(8, $lateTruth->quantity);
    }
}
