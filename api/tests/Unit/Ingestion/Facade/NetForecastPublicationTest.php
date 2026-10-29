<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Facade;

use App\Ingestion\Application\Facade\SalesCohortNetMetadata;
use App\Ingestion\Application\Facade\SalesCohortNetQuality;
use App\Ingestion\Domain\ValueObject\ObservedReturnCurve;
use App\Ingestion\Infrastructure\Config\NetForecastPublication;
use PHPUnit\Framework\TestCase;

final class NetForecastPublicationTest extends TestCase
{
    public function testOnlyExactCurrentModelVersionOpensPublication(): void
    {
        foreach (['', ' ', 'old-version', '1', 'true', ' '.ObservedReturnCurve::MODEL_VERSION, ObservedReturnCurve::MODEL_VERSION.' '] as $closed) {
            self::assertFalse((new NetForecastPublication($closed))->allowsModel(ObservedReturnCurve::MODEL_VERSION));
        }
        self::assertFalse((new NetForecastPublication())->allowsModel(''));
        self::assertTrue((new NetForecastPublication(ObservedReturnCurve::MODEL_VERSION))->allowsModel(ObservedReturnCurve::MODEL_VERSION));
        self::assertFalse((new NetForecastPublication(ObservedReturnCurve::MODEL_VERSION))->allowsModel('other'));
    }

    public function testPendingDoesNotClaimAnAssessmentOrInventReadContext(): void
    {
        $metadata = SalesCohortNetMetadata::pending();
        self::assertSame('observed_retention_h60_v1', $metadata->methodology);
        self::assertSame('net_training_observed_v1', $metadata->trainingVersion);
        self::assertSame(60, $metadata->horizonDays);
        self::assertSame('daily_complete_receipts_v1', $metadata->coveragePolicy);
        self::assertSame('placed_order_cohorts', $metadata->scope);
        self::assertSame('validation_pending', $metadata->validationStatus);
        self::assertSame('not_assessed', $metadata->assessmentStatus);
        self::assertNull($metadata->readAt);
        self::assertNull($metadata->epochId);
        self::assertNull($metadata->captureVersion);
        $quality = SalesCohortNetQuality::pending();
        self::assertNull($quality->sourceUnavailableReason);
        self::assertNull($quality->sourceMoneyUnavailableReason);
        self::assertSame([], $quality->trainingScopes);
        self::assertSame([], $quality->componentReasons);
        self::assertSame('validation_pending', $quality->validationStatus);
        self::assertSame('not_assessed', $quality->assessmentStatus);
    }
}
