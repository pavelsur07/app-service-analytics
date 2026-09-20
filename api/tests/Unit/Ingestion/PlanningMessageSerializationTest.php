<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use App\Ingestion\Application\Message\FetchOzonReturnsMessage;
use PHPUnit\Framework\TestCase;

final class PlanningMessageSerializationTest extends TestCase
{
    public function testOldPostingsPayloadIsMarkedAsLegacy(): void
    {
        $class = FetchOzonPostingsMessage::class;
        $message = unserialize(self::oldObject($class, [
            'companyId' => 'company', 'marketplaceAccountId' => 'account', 'businessDate' => '2026-09-20',
        ]), ['allowed_classes' => [$class]]);

        self::assertInstanceOf($class, $message);
        self::assertSame('legacy', $message->origin);
        self::assertNull($message->regularWindowFrom);
        self::assertNull($message->regularWindowTo);
    }

    public function testOldReturnsPayloadIsMarkedAsLegacy(): void
    {
        $class = FetchOzonReturnsMessage::class;
        $message = unserialize(self::oldObject($class, [
            'companyId' => 'company', 'marketplaceAccountId' => 'account',
            'from' => '2026-09-01', 'to' => '2026-09-20',
        ]), ['allowed_classes' => [$class]]);

        self::assertInstanceOf($class, $message);
        self::assertSame('legacy', $message->origin);
        self::assertNull($message->regularWindowFrom);
        self::assertNull($message->regularWindowTo);
    }

    /** @param array<string, string> $properties */
    private static function oldObject(string $class, array $properties): string
    {
        $array = serialize($properties);
        $start = strpos($array, '{');
        if (false === $start) {
            throw new \LogicException('Serialized array has no opening brace.');
        }

        return 'O:'.\strlen($class).':"'.$class.'":'.\count($properties).':'.substr($array, $start);
    }
}
