<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use App\Ingestion\Application\Message\FetchOzonReturnsMessage;
use App\Ingestion\Domain\FactCaptureOrigin;
use PHPUnit\Framework\TestCase;

final class FactCaptureOriginMessageTest extends TestCase
{
    public function testDefaultAndExplicitOrigin(): void
    {
        self::assertSame(FactCaptureOrigin::Unknown, (new FetchOzonPostingsMessage('c', 'a', '2026-10-28'))->captureOrigin());
        self::assertSame(FactCaptureOrigin::Unknown, (new FetchOzonReturnsMessage('c', 'a', '2026-10-01', '2026-10-28'))->captureOrigin());
        self::assertSame(FactCaptureOrigin::LivePolling, (new FetchOzonPostingsMessage('c', 'a', '2026-10-28', FactCaptureOrigin::LivePolling))->captureOrigin());
        self::assertSame(FactCaptureOrigin::HistoricalBackfill, (new FetchOzonReturnsMessage('c', 'a', '2026-10-01', '2026-10-28', FactCaptureOrigin::HistoricalBackfill))->captureOrigin());
    }

    public function testLegacyNativeSerializedMessagesHaveUnknownOrigin(): void
    {
        foreach ([
            [FetchOzonPostingsMessage::class, ['companyId' => 'c', 'marketplaceAccountId' => 'a', 'businessDate' => '2026-10-28']],
            [FetchOzonReturnsMessage::class, ['companyId' => 'c', 'marketplaceAccountId' => 'a', 'from' => '2026-10-01', 'to' => '2026-10-28']],
        ] as [$class, $fields]) {
            $body = '';
            foreach ($fields as $key => $value) {
                $body .= serialize($key).serialize($value);
            }
            $message = unserialize('O:'.\strlen($class).':"'.$class.'":'.\count($fields).':{'.$body.'}', ['allowed_classes' => [$class]]);
            self::assertInstanceOf($class, $message);
            self::assertSame(FactCaptureOrigin::Unknown, $message->captureOrigin());
            self::assertSame('c', $message->companyId);
        }
    }
}
