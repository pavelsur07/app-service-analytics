<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure;

use App\Identity\Infrastructure\Security\OtpAdminTotp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OtpAdminTotpTest extends TestCase
{
    private const string SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    #[DataProvider('rfcVectors')]
    public function testRfc6238SixDigitCodes(int $timestamp, string $code): void
    {
        self::assertSame(intdiv($timestamp, 30), (new OtpAdminTotp())->matchTimeStep(self::SECRET, $code, $timestamp));
    }

    /** @return iterable<array{int, string}> */
    public static function rfcVectors(): iterable
    {
        yield [59, '287082'];
        yield [1111111109, '081804'];
        yield [1111111111, '050471'];
        yield [1234567890, '005924'];
    }

    public function testOnlyAdjacentTimeStepsAndExactlySixDigitsAreAccepted(): void
    {
        $totp = new OtpAdminTotp();
        self::assertSame(1, $totp->matchTimeStep(self::SECRET, '287082', 89));
        self::assertNull($totp->matchTimeStep(self::SECRET, '287082', 90));
        self::assertNull($totp->matchTimeStep(self::SECRET, '94287082', 59));
        self::assertNull($totp->matchTimeStep(self::SECRET, 'abc123', 59));
    }
}
