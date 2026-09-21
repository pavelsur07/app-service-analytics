<?php

declare(strict_types=1);

namespace App\Tests\Unit\Planning;

use App\Planning\Domain\PlanImportPreviewRow;
use App\Tests\Support\Builder\PlanImportPreviewBuilder;
use PHPUnit\Framework\TestCase;

final class PlanImportPreviewTest extends TestCase
{
    public function testPreviewPreservesScopeRowsAndExpiresAfterTwentyFourHours(): void
    {
        $createdAt = new \DateTimeImmutable('2026-09-21 12:00:00+00:00');
        $preview = PlanImportPreviewBuilder::aPlanImportPreview()->withFingerprint(str_repeat('a', 64))
            ->withRows([new PlanImportPreviewRow(2, 'SKU-1', 'offer-1', '2026-09-22', 12, 0, null, 'new')])
            ->withCreatedAt($createdAt)->build();

        self::assertSame('ready', $preview->status());
        self::assertSame($createdAt->modify('+24 hours')->format(\DATE_ATOM), $preview->expiresAt()->format(\DATE_ATOM));
        self::assertFalse($preview->isExpired($createdAt->modify('+23 hours')));
        self::assertTrue($preview->isExpired($createdAt->modify('+24 hours')));
        self::assertSame('SKU-1', $preview->rows()[0]->marketplaceSku);
    }

    public function testAppliedPreviewKeepsStableResult(): void
    {
        $createdAt = new \DateTimeImmutable('2026-09-21 12:00:00+00:00');
        $preview = PlanImportPreviewBuilder::aPlanImportPreview()->withFingerprint(str_repeat('b', 64))
            ->withRows([])->withCreatedAt($createdAt)->build();

        $preview->markApplied(['created' => 1, 'updated' => 2, 'unchanged' => 3], $createdAt->modify('+1 minute'));

        self::assertSame('applied', $preview->status());
        self::assertSame(['created' => 1, 'updated' => 2, 'unchanged' => 3], $preview->result());
        self::assertSame([], $preview->rows());
        $this->expectException(\LogicException::class);
        $preview->markApplied(['created' => 0, 'updated' => 0, 'unchanged' => 1], $createdAt->modify('+2 minutes'));
    }
}
