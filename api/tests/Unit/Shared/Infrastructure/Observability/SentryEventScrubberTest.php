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

    public function testEventWithoutBreadcrumbsIsReturnedUnchanged(): void
    {
        $event = Event::createEvent();

        $result = (new SentryEventScrubber())($event, null);

        self::assertSame([], $result->getBreadcrumbs());
    }
}
