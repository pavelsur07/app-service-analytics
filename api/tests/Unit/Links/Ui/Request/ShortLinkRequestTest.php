<?php

declare(strict_types=1);

namespace App\Tests\Unit\Links\Ui\Request;

use App\Links\Domain\ShortLinkStatus;
use App\Links\Ui\Request\ChangeShortLinkStatusRequest;
use App\Links\Ui\Request\CreateShortLinkRequest;
use App\Links\Ui\Request\UpdateShortLinkRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShortLinkRequestTest extends TestCase
{
    public function testCreateTrimsValidInput(): void
    {
        $request = CreateShortLinkRequest::fromJson(
            '{"name":" Campaign ","targetUrl":" https://conwix.com/a "}',
        );

        self::assertSame('Campaign', $request->name);
        self::assertSame('https://conwix.com/a', $request->targetUrl);
    }

    #[DataProvider('invalidUrls')]
    public function testCreateRejectsUnsafeOrMalformedTarget(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('target_url_invalid');

        CreateShortLinkRequest::fromJson(json_encode([
            'name' => 'Campaign',
            'targetUrl' => $url,
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'ftp' => ['ftp://conwix.com/a'];
        yield 'relative' => ['/relative'];
        yield 'userinfo' => ['https://user:secret@conwix.com/a'];
        yield 'missing host' => ['https:///a'];
        yield 'too long' => ['https://example.com/'.str_repeat('a', 2049)];
    }

    public function testCreateRejectsMalformedJsonAndInvalidNames(): void
    {
        foreach ([
            ['body' => 'not-json', 'error' => 'malformed_json'],
            ['body' => '{"name":" ","targetUrl":"https://example.com"}', 'error' => 'name_invalid'],
            ['body' => json_encode(['name' => str_repeat('a', 121), 'targetUrl' => 'https://example.com'], \JSON_THROW_ON_ERROR), 'error' => 'name_invalid'],
        ] as $case) {
            try {
                CreateShortLinkRequest::fromJson($case['body']);
                self::fail('Invalid request was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame($case['error'], $error->getMessage());
            }
        }
    }

    public function testUpdateRequiresPositiveIntegerVersion(): void
    {
        foreach ([null, 0, -1, '1'] as $version) {
            try {
                UpdateShortLinkRequest::fromJson(json_encode([
                    'name' => 'Campaign',
                    'targetUrl' => 'https://example.com',
                    'version' => $version,
                ], \JSON_THROW_ON_ERROR));
                self::fail('Invalid version was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('version_invalid', $error->getMessage());
            }
        }
    }

    public function testStatusRequiresKnownValueAndPositiveVersion(): void
    {
        $valid = ChangeShortLinkStatusRequest::fromJson('{"status":"disabled","version":2}');
        self::assertSame(ShortLinkStatus::Disabled, $valid->status);
        self::assertSame(2, $valid->version);

        foreach ([
            '{"status":"deleted","version":2}',
            '{"status":"active","version":0}',
        ] as $body) {
            try {
                ChangeShortLinkStatusRequest::fromJson($body);
                self::fail('Invalid status request was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertContains($error->getMessage(), ['status_invalid', 'version_invalid']);
            }
        }
    }
}
