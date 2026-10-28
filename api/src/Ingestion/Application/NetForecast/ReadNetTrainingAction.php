<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Infrastructure\Config\NetForecastCaptureEpochs;
use App\Ingestion\Infrastructure\Query\NetForecast\GrossTrainingAggregateQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingRequest;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAggregateQuery;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

/** Current model inputs only. Historical visibility requires the later B15 manifest. */
final readonly class ReadNetTrainingAction
{
    public function __construct(
        private Connection $connection,
        private GrossTrainingAggregateQuery $gross,
        private ReturnTrainingAggregateQuery $returns,
        private NetForecastCaptureEpochs $epochs,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    public function __invoke(string $companyId, string $accountId, NetTrainingRequest $request): NetTrainingBatch
    {
        if (!Uuid::isValid($companyId) || !Uuid::isValid($accountId)) {
            throw new \InvalidArgumentException('Training company and account must be UUID values.');
        }
        $readAt = null;
        try {
            return (new NetTrainingSnapshot($this->connection))->read(function (string $snapshotId) use ($companyId, $accountId, $request, &$readAt): NetTrainingBatch {
                $readAt = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
                try {
                    $epoch = $this->epochs->at($readAt);
                } catch (\InvalidArgumentException) {
                    $epoch = null;
                }
                if (null === $epoch) {
                    return new NetTrainingBatch($companyId, $accountId, $readAt, null, [], [], 'source_history_unavailable');
                }
                $context = new NetTrainingContext($companyId, $accountId, $readAt, $epoch, $snapshotId);
                $this->connection->executeStatement('SET LOCAL jit = off');
                $this->connection->executeStatement("SET LOCAL statement_timeout = '5s'");
                $gross = $this->gross->build($companyId, $accountId, $context, $request)->executeQuery()->fetchAllAssociative();
                $returns = $this->returns->build($companyId, $accountId, $context, $request)->executeQuery()->fetchAllAssociative();

                return new NetTrainingBatch($companyId, $accountId, $readAt, $context, array_map(GrossTrainingAggregateQuery::mapRow(...), $gross), array_map(ReturnTrainingAggregateQuery::mapRow(...), $returns));
            });
        } catch (DriverException $error) {
            if ('57014' !== $error->getSQLState()) {
                throw $error;
            }

            return new NetTrainingBatch($companyId, $accountId, $readAt ?? $this->clock->now()->setTimezone(new \DateTimeZone('UTC')), null, [], [], 'query_budget_exceeded');
        }
    }
}
