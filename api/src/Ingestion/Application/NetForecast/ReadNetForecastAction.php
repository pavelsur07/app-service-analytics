<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetLimits;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetRequest;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\Uid\Uuid;

/** Current targets and training share one real read-only visibility snapshot. */
final readonly class ReadNetForecastAction
{
    public function __construct(
        private Connection $connection,
        private ReadNetTrainingAction $training,
        private NetTargetQuery $targets,
        private AggregateNetForecastAction $aggregate,
        private NetTargetLimits $limits = new NetTargetLimits(),
    ) {
    }

    public function __invoke(string $companyId, string $accountId, NetTargetRequest $request, ?NetForecastSeriesRequest $seriesRequest = null): NetForecastBatch
    {
        if (!Uuid::isValid($companyId) || !Uuid::isValid($accountId)) {
            throw new \InvalidArgumentException('Forecast company and account must be UUID values.');
        }
        $seriesRequest?->assertMatches($request);
        if ([] === $request->marketplaceSkus) {
            return new NetForecastBatch($companyId, $accountId, null, null, [], NetForecastQuantities::zero(), null, null, 'money_currency_missing', null === $seriesRequest ? null : new NetForecastSeries([]));
        }
        $readAt = null;
        $context = null;
        try {
            return (new NetTrainingSnapshot($this->connection))->read(function (string $snapshotId) use ($companyId, $accountId, $request, $seriesRequest, &$readAt, &$context): NetForecastBatch {
                $frame = (new ReadNetForecastInputsAction($this->connection, $this->training, $this->targets, $this->limits))($companyId, $accountId, $request, $snapshotId);
                $readAt = $frame->training->readAt;
                $context = $frame->training->context;
                if (null === $frame->targets) {
                    return $this->unavailable($companyId, $accountId, $readAt, $context, $frame->unavailableReason ?? 'training_context_missing');
                }

                return ($this->aggregate)($companyId, $accountId, $frame->targets, $frame->training, $frame->computeStartedNs, $frame->allocationBaselineBytes, $frame->limits, $seriesRequest);
            });
        } catch (DriverException $error) {
            if ('57014' !== $error->getSQLState()) {
                throw $error;
            }

            return $this->unavailable($companyId, $accountId, $readAt, $context, 'query_budget_exceeded');
        }
    }

    private function unavailable(string $companyId, string $accountId, ?\DateTimeImmutable $readAt, ?NetTrainingContext $context, string $reason): NetForecastBatch
    {
        return new NetForecastBatch($companyId, $accountId, $readAt, $context, [], null, $reason, null, $reason);
    }
}
