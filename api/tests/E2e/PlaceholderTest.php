<?php

declare(strict_types=1);

namespace App\Tests\E2e;

use PHPUnit\Framework\TestCase;

/**
 * Заглушка: реальный e2e — Playwright поверх собранных фронтендов,
 * не PHPUnit. Появится на Stage 2, шаг 2. Существует, чтобы набор
 * `e2e` был объявлен и запускался (зелёным) уже сейчас.
 */
final class PlaceholderTest extends TestCase
{
    public function testPlaceholder(): void
    {
        self::markTestSkipped('e2e — Playwright, Stage 2 шаг 2');
    }
}
