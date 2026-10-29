<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Application\QueryTelemetry;
use App\Shared\Infrastructure\Doctrine\QueryTimingMiddleware;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class QueryTimingMiddlewareTest extends KernelTestCase
{
    public function testDriverCountsRepeatedQueriesButNeverLogsBoundValues(): void
    {
        self::bootKernel(['debug' => false]);
        $existing = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $existing);
        $params = $existing->getParams();
        unset($params['wrapperClass']);
        $handler = new TestHandler();
        $trace = new QueryTelemetry(new Logger('capture_test', [$handler]));
        $config = new Configuration();
        $config->setMiddlewares([new QueryTimingMiddleware($trace)]);
        $db = DriverManager::getConnection($params, $config);
        try {
            $db->fetchOne('SELECT 1');
            $trace->run('net_forecast.capture', '', '', '', static function () use ($db, $trace): void {
                $trace->stage('test.read', static function () use ($db): void {
                    self::assertSame('private-order-1', $db->fetchOne('SELECT CAST(? AS text)', ['private-order-1']));
                    self::assertSame('private-order-2', $db->fetchOne('SELECT CAST(? AS text)', ['private-order-2']));
                });
            });
            $records = $handler->getRecords();
            self::assertCount(1, $records);
            self::assertSame(2, $records[0]->context['sql_count']);
            self::assertSame(1, $records[0]->context['query_group_count']);
            self::assertStringNotContainsString('private-order', json_encode($records[0]->context, \JSON_THROW_ON_ERROR));
        } finally {
            $db->close();
        }
    }
}
