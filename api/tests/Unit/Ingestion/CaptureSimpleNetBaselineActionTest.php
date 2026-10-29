<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\NetForecast\CaptureSimpleNetBaselineAction;
use App\Ingestion\Application\NetForecast\NetForecastBatch;
use App\Ingestion\Application\NetForecast\NetForwardInputCodec;
use App\Ingestion\Application\NetForecast\NetForwardManifestCodec;
use App\Ingestion\Application\NetForecast\NetForwardSpool;
use App\Ingestion\Domain\ValueObject\NetForecastCaptureEpoch;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetBatch;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetDayRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetGroupRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetPartRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetSkuRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetSourceTrace;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CaptureSimpleNetBaselineActionTest extends TestCase
{
    public function testMoneyOverflowDoesNotDiscardSimpleBaselineQuantity(): void
    {
        $readAt = new \DateTimeImmutable('2026-01-01T12:00:00Z');
        $company = Uuid::v7()->toRfc4122();
        $account = Uuid::v7()->toRfc4122();
        $epoch = new NetForecastCaptureEpoch(Uuid::v7()->toRfc4122(), NetForecastCaptureEpoch::CAPTURE_VERSION, $readAt->modify('-100 days'), null, 'test-release');
        $context = new NetTrainingContext($company, $account, $readAt, $epoch, 'snapshot');
        $trace = new NetTargetSourceTrace('source', null, null, 1, null, null, 'raw', null, (string) \PHP_INT_MAX, 'RUB', [], [], null);
        $part = new NetTargetPartRow('source', 'posting', 2, 2, 0, 0, 0, 0, 0, 0, 0, $readAt->modify('-70 days'), null, $trace, true, null);
        $group = new NetTargetGroupRow('order', 'SKU', '2026-01-01', [$part], [], true, null);
        $targets = new NetTargetBatch($context, [new NetTargetSkuRow('SKU', [new NetTargetDayRow('2026-01-01', [$group], false, null)])]);
        $forecast = new NetForecastBatch($company, $account, $readAt, $context, [], null, null, null, null);
        $codec = new NetForwardManifestCodec();
        $spool = new NetForwardSpool($codec);

        (new CaptureSimpleNetBaselineAction(new NetForwardInputCodec()))($targets, $forecast, [], $spool);

        $payload = $codec->decode($spool->body($spool->descriptors()[0]['ordinal']));
        $rows = $payload['rows'] ?? null;
        if ('simple_baseline_part' !== ($payload['type'] ?? null) || !\is_array($rows) || !\is_array($rows[0] ?? null)) {
            throw new \UnexpectedValueException('Invalid simple baseline spool output.');
        }
        $row = $rows[0];
        self::assertIsArray($row['quantities'] ?? null);
        self::assertNull($row['money'] ?? null);
        self::assertSame('money_overflow', $row['moneyReason'] ?? null);
    }
}
