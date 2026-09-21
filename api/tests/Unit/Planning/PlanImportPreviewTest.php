<?php

declare(strict_types=1);

namespace App\Tests\Unit\Planning;

use App\Planning\Domain\PlanImportPreview;
use App\Planning\Domain\PlanImportPreviewRow;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PlanImportPreviewTest extends TestCase
{
    public function testPreviewPreservesScopeRowsAndExpiresAfterTwentyFourHours(): void
    {
        $createdAt = new \DateTimeImmutable('2026-09-21 12:00:00+00:00');
        $preview = PlanImportPreview::create(
            Uuid::v7(),
            Uuid::v7(),
            Uuid::v7(),
            str_repeat('a', 64),
            [new PlanImportPreviewRow(2, 'SKU-1', '2026-09-22', 12, 0, null, 'new')],
            $createdAt,
        );

        self::assertSame('ready', $preview->status());
        self::assertSame($createdAt->modify('+24 hours')->format(DATE_ATOM), $preview->expiresAt()->format(DATE_ATOM));
        self::assertFalse($preview->isExpired($createdAt->modify('+23 hours')));
        self::assertTrue($preview->isExpired($createdAt->modify('+24 hours')));
        self::assertSame('SKU-1', $preview->rows()[0]->marketplaceSku);
    }

    public function testAppliedPreviewKeepsStableResult(): void
    {
        $createdAt = new \DateTimeImmutable('2026-09-21 12:00:00+00:00');
        $preview = PlanImportPreview::create(Uuid::v7(), Uuid::v7(), Uuid::v7(), str_repeat('b', 64), [], $createdAt);

        $preview->markApplied(['created' => 1, 'updated' => 2, 'unchanged' => 3], $createdAt->modify('+1 minute'));

        self::assertSame('applied', $preview->status());
        self::assertSame(['created' => 1, 'updated' => 2, 'unchanged' => 3], $preview->result());
        $this->expectException(\LogicException::class);
        $preview->markApplied(['created' => 0], $createdAt->modify('+2 minutes'));
    }
}
