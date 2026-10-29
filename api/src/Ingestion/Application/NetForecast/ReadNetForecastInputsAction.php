<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetLimits;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetRequest;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingRequest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/** Shared current materialization, called inside the already pinned outer RR. */
final readonly class ReadNetForecastInputsAction
{
    public function __construct(private Connection $connection, private ReadNetTrainingAction $training, private NetTargetQuery $targets, private NetTargetLimits $limits = new NetTargetLimits())
    {
    }

    public function __invoke(string $companyId, string $accountId, NetTargetRequest $request, string $snapshotId): NetForecastReadFrame
    {
        $training = ($this->training)($companyId, $accountId, new NetTrainingRequest($request->marketplaceSkus));
        $context = $training->context;
        if (null === $context) {
            return new NetForecastReadFrame($training, null, hrtime(true), memory_get_usage(true), $this->limits, $training->unavailableReason ?? 'training_context_missing');
        }
        $context->assertScope($companyId, $accountId);
        if ($context->snapshotId !== $snapshotId) {
            return new NetForecastReadFrame($training, null, hrtime(true), memory_get_usage(true), $this->limits, 'training_context_mismatch');
        }
        try {
            // The nested training guard restored the caller's settings.
            $this->connection->executeStatement('SET LOCAL jit = off');
            $this->connection->executeStatement("SET LOCAL statement_timeout = '5s'");
            $allocation = memory_get_usage(true);
            $headroom = min($this->limits->phpAllocationBytes, $this->limits->phpPeakBytes - $allocation - 8388608);
            if ($headroom <= 0 || memory_get_peak_usage(true) > $this->limits->phpPeakBytes) {
                return new NetForecastReadFrame($training, null, hrtime(true), $allocation, $this->limits, 'input_budget_exceeded');
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
            $this->connection->createSavepoint('net_forecast_target_read');
            try {
                $rows = $query->executeQuery()->fetchAllAssociative();
                $this->connection->releaseSavepoint('net_forecast_target_read');
            } catch (DriverException $error) {
                if ('57014' !== $error->getSQLState()) {
                    throw $error;
                }
                $this->connection->rollbackSavepoint('net_forecast_target_read');
                $this->connection->releaseSavepoint('net_forecast_target_read');
                throw $error;
            }
            unset($query);
            if (memory_get_usage(true) - $allocation > $this->limits->phpAllocationBytes || memory_get_usage(true) > $this->limits->phpPeakBytes) {
                return new NetForecastReadFrame($training, null, hrtime(true), $allocation, $this->limits, 'input_budget_exceeded');
            }
            $computeStarted = hrtime(true);
            $targets = $this->targets->mapRows($context, $request, $rows);
            unset($rows);

            return new NetForecastReadFrame($training, $targets, $computeStarted, $allocation, $this->limits);
        } catch (DriverException $error) {
            if ('57014' !== $error->getSQLState()) {
                throw $error;
            }

            return new NetForecastReadFrame($training, null, hrtime(true), memory_get_usage(true), $this->limits, 'query_budget_exceeded');
        }
    }
}
