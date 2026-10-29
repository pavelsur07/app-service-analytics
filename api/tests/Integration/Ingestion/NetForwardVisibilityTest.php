<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\NetForecast\NetTrainingSnapshot;
use App\Ingestion\Application\NetForecast\ReadNetForecastInputsAction;
use App\Ingestion\Application\NetForecast\ReadNetTrainingAction;
use App\Ingestion\Infrastructure\Config\NetForecastCaptureEpochs;
use App\Ingestion\Infrastructure\Query\NetForecast\GrossTrainingAggregateQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetRequest;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAggregateQuery;
use App\Tests\Support\Ingestion\NetTargetFixture;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

#[SkipDatabaseRollback]
final class NetForwardVisibilityTest extends KernelTestCase
{
    public function testFrameUsesOnePinnedContextAndExactReadAt(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $params = $connection->getParams();
        unset($params['wrapperClass']);
        $db = DriverManager::getConnection($params);
        $f = new NetTargetFixture($db);
        try {
            $f->delivered('D', 'SKU', 10, $f->readAt->modify('-1 day'));
            $f->daily($f->readAt->modify('-1 day'));
            $f->currentReturns();
            $e = $f->epoch;
            $epochs = new NetForecastCaptureEpochs(json_encode([['epochId' => $e->id, 'captureVersion' => $e->captureVersion, 'startUtc' => $e->start->format('Y-m-d\TH:i:s\Z'), 'endUtc' => null, 'cutoverRelease' => $e->cutoverRelease]], \JSON_THROW_ON_ERROR));
            $training = new ReadNetTrainingAction($db, new GrossTrainingAggregateQuery($db), new ReturnTrainingAggregateQuery($db), $epochs, new MockClock($f->readAt));
            $inputs = new ReadNetForecastInputsAction($db, $training, new NetTargetQuery($db));
            $day = new \DateTimeImmutable($f->readAt->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d'));
            $frame = (new NetTrainingSnapshot($db))->read(static fn (string $snapshot) => $inputs($f->company->toRfc4122(), $f->account->toRfc4122(), new NetTargetRequest(['SKU'], $day, $day), $snapshot));
            self::assertNotNull($frame->targets);
            self::assertSame($frame->training->context, $frame->targets->context);
            self::assertSame($f->readAt->format('U.u'), $frame->training->readAt->format('U.u'));
            self::assertLessThanOrEqual(hrtime(true), $frame->computeStartedNs);
            self::assertFalse($db->isTransactionActive());
        } finally {
            $f->cleanup();
            $db->close();
        }
    }
}
