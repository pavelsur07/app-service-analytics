<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\ServiceCoverage;

use App\Ingestion\Infrastructure\ServiceCoverage\OzonFetcherAllowlist;
use PHPUnit\Framework\TestCase;

final class OzonFetcherAllowlistTest extends TestCase
{
    public function testItReadsTheSameListAsTheService(): void
    {
        $allowlist = new OzonFetcherAllowlist(' 019FE6EA-CD99-7AF8-BF4A-623A5A31CF7B, ,019fe6ea-cd99-7af8-bf4a-623a5a31cf7b,01a17223-68d8-7223-b10a-82c83f9fb302');

        self::assertSame(['019fe6ea-cd99-7af8-bf4a-623a5a31cf7b', '01a17223-68d8-7223-b10a-82c83f9fb302'], $allowlist->accountIds);
        self::assertSame([], new OzonFetcherAllowlist('')->accountIds);
    }

    public function testInvalidEntryIsRejectedNotSkipped(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        new OzonFetcherAllowlist('019fe6ea-cd99-7af8-bf4a-623a5a31cf7b,not-a-uuid');
    }
}
