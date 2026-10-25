<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Observability;

use App\Shared\Infrastructure\Observability\SentryEventScrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;

final class SentryEventScrubberTest extends TestCase
{
    public function testSensitiveHeadersAreRedactedRegardlessOfCase(): void
    {
        $breadcrumb = new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_HTTP,
            'http',
            null,
            [
                'headers' => ['Api-Key' => 'secret-value', 'Content-Type' => 'application/json'],
                'AUTHORIZATION' => 'Bearer secret-token',
            ],
        );
        $event = Event::createEvent();
        $event->setBreadcrumb([$breadcrumb]);

        $scrubbed = (new SentryEventScrubber())($event, null);

        $metadata = $scrubbed->getBreadcrumbs()[0]->getMetadata();
        $headers = $metadata['headers'];
        self::assertIsArray($headers);
        self::assertSame('[scrubbed]', $headers['Api-Key']);
        self::assertSame('application/json', $headers['Content-Type']);
        self::assertSame('[scrubbed]', $metadata['AUTHORIZATION']);
    }

    public function testRequestBodyAndMfaSecretsAreRemovedBeforeDelivery(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://admin.example.test/api/admin/auth/mfa/verify', 'method' => 'POST', 'data' => ['code' => 'synthetic-recovery-code']]);
        $event->setExtra(['secret' => 'synthetic-totp-secret', 'recoveryCodes' => ['first-code', 'second-code'], 'reason' => 'mfa_invalid']);
        $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', null, ['headers' => ['Api-Key' => ['synthetic-api-key']], 'provisioningUri' => 'otpauth://synthetic'])]);

        $scrubbed = (new SentryEventScrubber())($event, null);

        self::assertArrayNotHasKey('data', $scrubbed->getRequest());
        self::assertSame('POST', $scrubbed->getRequest()['method']);
        self::assertSame('[scrubbed]', $scrubbed->getExtra()['secret']);
        self::assertSame('[scrubbed]', $scrubbed->getExtra()['recoveryCodes']);
        self::assertSame('mfa_invalid', $scrubbed->getExtra()['reason']);
        $metadata = $scrubbed->getBreadcrumbs()[0]->getMetadata();
        self::assertIsArray($metadata['headers']);
        self::assertSame('[scrubbed]', $metadata['headers']['Api-Key']);
        self::assertSame('[scrubbed]', $metadata['provisioningUri']);
    }

    public function testEventWithoutBreadcrumbsIsReturnedUnchanged(): void
    {
        $event = Event::createEvent();

        $result = (new SentryEventScrubber())($event, null);

        self::assertSame([], $result->getBreadcrumbs());
    }
}
