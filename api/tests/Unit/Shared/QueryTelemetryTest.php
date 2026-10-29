<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\QueryTelemetry;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class QueryTelemetryTest extends TestCase
{
    public function testFailureKeepsAggregateTimingWithoutSqlOrParameterValuesAndResetsScope(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array<string, mixed>> */
            public array $events = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->events[] = $context;
            }
        };
        $trace = new QueryTelemetry($logger);
        $failure = new \RuntimeException('private exception payload');
        try {
            $trace->run('net_forecast.capture', '01940000-0000-7000-8000-000000000001', '01940000-0000-7000-8000-000000000002', '01940000-0000-7000-8000-000000000003', static function () use ($trace, $failure): never {
                $trace->stage('evidence.gross', static function () use ($trace, $failure): never {
                    $trace->recordSql("SELECT 'secret-order-number'", 123000000, false);
                    $trace->recordSql("SELECT 'secret-order-number'", 210000000, true);
                    throw $failure;
                });
            });
        } catch (\RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }
        self::assertCount(1, $logger->events);
        $event = $logger->events[0];
        self::assertSame(2, $event['sql_count']);
        self::assertSame(333, $event['sql_ms']);
        self::assertSame('failed', $event['status']);
        self::assertStringNotContainsString('secret-order', json_encode($event, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private exception', json_encode($event, \JSON_THROW_ON_ERROR));
        $trace->recordSql('SELECT 1', 1000000, false);
        $trace->run('net_forecast.capture', '', '', '', static fn (): bool => true);
        self::assertSame(0, $logger->events[1]['sql_count']);
        self::assertNull($logger->events[1]['company_id']);
    }
}
