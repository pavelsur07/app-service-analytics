<?php

declare(strict_types=1);

namespace App\Tests\Unit\Links\Domain;

use App\Links\Domain\BotDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BotDetectorTest extends TestCase
{
    #[DataProvider('agents')]
    public function testClassifiesAgents(?string $agent, bool $expected): void
    {
        self::assertSame($expected, (new BotDetector())->isBot($agent));
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function agents(): iterable
    {
        yield 'missing' => [null, true];
        yield 'blank' => ['   ', true];
        yield 'crawler' => ['Googlebot/2.1', true];
        yield 'AI crawler' => ['Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)', true];
        yield 'mail scanner' => ['Proofpoint URL Defense', true];
        yield 'Facebook preview' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', true];
        yield 'WhatsApp preview' => ['WhatsApp/2.23.20.0 A', true];
        yield 'cli' => ['curl/8.10.1', true];
        yield 'invalid UTF-8' => ["Mozilla/5.0\xFF", true];
        yield 'browser' => ['Mozilla/5.0 Chrome/130.0 Safari/537.36', false];
        yield 'Cubot Android device' => [
            'Mozilla/5.0 (Linux; Android 12; CUBOT NOTE 21) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36',
            false,
        ];
    }
}
