<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetLimits;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetRequest;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingRequest;
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

    public function __invoke(string $companyId, string $accountId, NetTargetRequest $request): NetForecastBatch
    {
        if (!Uuid::isValid($companyId) || !Uuid::isValid($accountId)) {
            throw new \InvalidArgumentException('Forecast company and account must be UUID values.');
        }
        if ([] === $request->marketplaceSkus) {
            return new NetForecastBatch($companyId, $accountId, null, null, [], NetForecastQuantities::zero(), null);
        }
        $readAt = null;
        $context = null;
        try {
            return (new NetTrainingSnapshot($this->connection))->read(function (string $snapshotId) use ($companyId, $accountId, $request, &$readAt, &$context): NetForecastBatch {
                $training = ($this->training)($companyId, $accountId, new NetTrainingRequest($request->marketplaceSkus));
                $readAt = $training->readAt;
                $context = $training->context;
                if (null === $context) {
                    return new NetForecastBatch($companyId, $accountId, $readAt, null, [], null, $training->unavailableReason ?? 'training_context_missing');
                }
                $context->assertScope($companyId, $accountId);
                if ($context->snapshotId !== $snapshotId) {
                    return $this->unavailable($companyId, $accountId, $readAt, $context, 'training_context_mismatch');
                }
                // The nested training guard restored the caller's settings.
                $this->connection->executeStatement('SET LOCAL jit = off');
                $this->connection->executeStatement("SET LOCAL statement_timeout = '5s'");
                $allocation = memory_get_usage(true);
                $headroom = min($this->limits->phpAllocationBytes, $this->limits->phpPeakBytes - $allocation - 8388608);
                if ($headroom <= 0 || memory_get_peak_usage(true) > $this->limits->phpPeakBytes) {
                    return $this->unavailable($companyId, $accountId, $readAt, $context, 'input_budget_exceeded');
                }
                $query = $this->targets->build($companyId, $accountId, $context, $request);
                // Reduce the SQL envelope before JSON construction/transfer; never enlarge query caps.
                $queryAllocation = $query->getParameter('maxPhpAllocationBytes');
                $queryOutput = $query->getParameter('maxOutputBytes');
                if (!\is_int($queryAllocation) || !\is_int($queryOutput)) {
                    throw new \LogicException('Target query must declare integer transfer budgets.');
                }
                $query->setParameter('maxPhpAllocationBytes', min($queryAllocation, $headroom));
                $query->setParameter('maxOutputBytes', min($queryOutput, $this->limits->outputBytes));
                $rows = $query->executeQuery()->fetchAllAssociative();
                unset($query);
                if (memory_get_usage(true) - $allocation > $this->limits->phpAllocationBytes || memory_get_usage(true) > $this->limits->phpPeakBytes) {
                    return $this->unavailable($companyId, $accountId, $readAt, $context, 'input_budget_exceeded');
                }
                $targets = $this->targets->mapRows($context, $request, $rows);
                unset($rows);

                return ($this->aggregate)($companyId, $accountId, $targets, $training);
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
        return new NetForecastBatch($companyId, $accountId, $readAt, $context, [], null, $reason);
    }
}
