<?php

declare(strict_types=1);

namespace App\Tests\Unit\Links\Infrastructure;

use App\Links\Infrastructure\RandomShortCodeGenerator;
use PHPUnit\Framework\TestCase;

final class RandomShortCodeGeneratorTest extends TestCase
{
    public function testGeneratesExactlySevenBase62Characters(): void
    {
        $generator = new RandomShortCodeGenerator();

        for ($i = 0; $i < 100; ++$i) {
            self::assertMatchesRegularExpression('/^[0-9A-Za-z]{7}$/D', $generator->generate());
        }
    }
}
