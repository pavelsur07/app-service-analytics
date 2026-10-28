<?php

declare(strict_types=1);

namespace App\Tests\Support\Ingestion;

use App\Ingestion\Application\NetForecast\AggregateNetForecastAction;
use App\Ingestion\Application\NetForecast\ApplyOpenRetentionAction;
use App\Ingestion\Application\NetForecast\AssessDeliveredRiskAction;
use App\Ingestion\Application\NetForecast\AssessOpenRetentionAction;
use App\Ingestion\Application\NetForecast\BuildPostHandoverGrossRateAction;
use App\Ingestion\Application\NetForecast\BuildPreHandoverGrossRateAction;
use App\Ingestion\Application\NetForecast\BuildReturnCurveAction;
use App\Ingestion\Application\NetForecast\NetTrainingSnapshot;
use App\Ingestion\Application\NetForecast\ReadNetForecastAction;
use App\Ingestion\Application\NetForecast\ReadNetTrainingAction;
use App\Ingestion\Domain\ValueObject\NetForecastCaptureEpoch;
use App\Ingestion\Infrastructure\Config\NetForecastCaptureEpochs;
use App\Ingestion\Infrastructure\Query\NetForecast\GrossTrainingAggregateQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetLimits;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetRequest;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingRequest;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAggregateQuery;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Result;
use Symfony\Component\Clock\MockClock;

\define('NET_FORECAST_MEASURE_WORKER', true);
require __DIR__.'/benchmark-net-forecast.php';

/** Observes real execution; no substituted rows, queries or planner overrides. */
final class NetForecastBenchmarkConnection extends Connection
{
    /** @var list<array{population: string, milliseconds: float, snapshot: mixed, readAt: mixed}> */
    public array $measurements = [];

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        $population = str_starts_with($sql, 'EXPLAIN') ? null : (str_contains($sql, 'result.refusal_reason') ? 'target' : (str_contains($sql, 'result.age_profile') ? 'returns' : (str_contains($sql, 'result.p95_seconds') ? 'gross' : null)));
        $snapshot = null === $population ? null : $this->fetchOne('SELECT txid_current_snapshot()::text');
        $started = hrtime(true);
        try {
            return parent::executeQuery($sql, $params, $types, $qcp);
        } finally {
            if (null !== $population) {
                $this->measurements[] = ['population' => $population, 'milliseconds' => (hrtime(true) - $started) / 1000000, 'snapshot' => $snapshot, 'readAt' => $params['asOf'] ?? null];
            }
        }
    }
}

[$kernel, $original, $database] = NetForecastBenchmarkSupport::boot();
if (!isset($argv[1], $argv[2], $argv[3]) || '128M' !== \ini_get('memory_limit')) {
    throw new \RuntimeException('Usage: php -d memory_limit=128M measure-net-forecast.php manifest output mode.');
}
$m = NetForecastBenchmarkSupport::manifest($argv[1]);
NetForecastBenchmarkSupport::verify($original, $database, $m);
foreach (['companyId', 'accountId', 'epochId', 'epochStart', 'readAt'] as $key) {
    if (!\is_string($m[$key])) {
        throw new \RuntimeException('Invalid measurement context.');
    }
}
$company = (string) $m['companyId'];
$account = (string) $m['accountId'];
$at = new \DateTimeImmutable((string) $m['readAt']);
$epoch = new NetForecastCaptureEpoch((string) $m['epochId'], 'accepted_state_v1', new \DateTimeImmutable((string) $m['epochStart']), null, 'synthetic-task5');
$params = $original->getParams();
$params['wrapperClass'] = NetForecastBenchmarkConnection::class;
$db = DriverManager::getConnection($params);
if (!$db instanceof NetForecastBenchmarkConnection || $db->fetchOne('SELECT current_database()') !== $database) {
    throw new \RuntimeException('Missing real observed connection.');
}
$request = new NetTargetRequest(array_map(static fn (int $i): string => 'BENCH-'.$i, range(0, 49)), $at->modify('-184 days'), $at->modify('-1 day'));
$epochs = new NetForecastCaptureEpochs(json_encode([['epochId' => $epoch->id, 'captureVersion' => $epoch->captureVersion, 'startUtc' => $epoch->start->format('Y-m-d\TH:i:s\Z'), 'endUtc' => null, 'cutoverRelease' => $epoch->cutoverRelease]], \JSON_THROW_ON_ERROR));
$trainingAction = new ReadNetTrainingAction($db, new GrossTrainingAggregateQuery($db), new ReturnTrainingAggregateQuery($db), $epochs, new MockClock($at));
$curves = new BuildReturnCurveAction();
$pre = new BuildPreHandoverGrossRateAction();
$post = new BuildPostHandoverGrossRateAction();
$limits = match ($argv[3]) {
    'groups-sentinel' => new NetTargetLimits(groups: 19999),
    'keys-sentinel' => new NetTargetLimits(sourceKeys: 49999),
    default => new NetTargetLimits(),
};
$targetQuery = new NetTargetQuery($db, $limits);
$aggregate = new AggregateNetForecastAction(new AssessDeliveredRiskAction($curves), new AssessOpenRetentionAction($pre, $post, $curves, new ApplyOpenRetentionAction($pre, $post, $curves)), $limits);
$baselineBytes = memory_get_usage(true);
$mapperMs = $aggregateMs = $targetTransferMs = 0.0;
$result = ['sourceHashes' => [], 'plans' => [], 'probes' => [], 'normalGatesPassed' => false, 'mode' => $argv[3], 'fixtureManifestSha256' => hash_file('sha256', $argv[1]), 'fixture' => $m, 'status' => 'running', 'memoryLimit' => \ini_get('memory_limit'), 'baselineBytes' => $baselineBytes, 'measurementScriptSha256' => hash_file('sha256', __FILE__), 'loadedSupportScriptSha256' => hash_file('sha256', __DIR__.'/benchmark-net-forecast.php')];
foreach (['Domain/ValueObject/NetForecastQuantities.php', 'Infrastructure/Query/NetForecast/NetTargetLimits.php', 'Infrastructure/Query/NetForecast/GrossTrainingAggregateQuery.php', 'Infrastructure/Query/NetForecast/ReturnTrainingAggregateQuery.php', 'Infrastructure/Query/NetForecast/NetTargetQuery.php', 'Infrastructure/Query/NetForecast/NetTargetSourceSql.php', 'Infrastructure/Query/NetForecast/NetTargetProofSql.php', 'Infrastructure/Query/NetForecast/NetTrainingSourceSql.php', 'Infrastructure/Query/NetForecast/NetTrainingOutcomeSql.php', 'Application/NetForecast/AggregateNetForecastAction.php', 'Application/NetForecast/ReadNetForecastAction.php'] as $source) {
    $result['sourceHashes'][$source] = hash_file('sha256', \dirname(__DIR__, 3).'/src/Ingestion/'.$source);
}
NetForecastBenchmarkSupport::save($argv[2], $result);
$started = hrtime(true);
$failed = false;
if ('stage-profile' === $argv[3]) {
    $result['diagnosticOnly'] = true;
}
try {
    $batch = null;
    if ('combined' === $argv[3]) {
        $batch = (new ReadNetForecastAction($db, $trainingAction, $targetQuery, $aggregate, $limits))($company, $account, $request);
    } else {
        (new NetTrainingSnapshot($db))->read(static function (string $snapshot) use ($db, $company, $account, $epoch, $at, $argv, $request, $targetQuery, $trainingAction, $aggregate, $limits, &$result, &$batch, &$mapperMs, &$aggregateMs, &$targetTransferMs): void {
            $context = new NetTrainingContext($company, $account, $at, $epoch, $snapshot);
            $db->executeStatement('SET LOCAL jit = off');
            $db->executeStatement("SET LOCAL statement_timeout = '5s'");
            $query = $targetQuery->build($company, $account, $context, $request);
            if ('returns-probe' === $argv[3]) {
                $result['diagnosticOnly'] = true;
                $db->executeStatement("SET LOCAL statement_timeout = '30s'");
                $q = (new ReturnTrainingAggregateQuery($db))->build($company, $account, $context, new NetTrainingRequest($request->marketplaceSkus));
                $raw = $db->fetchOne('EXPLAIN (FORMAT JSON) '.$q->getSQL(), $q->getParameters(), $q->getParameterTypes());
                if (!\is_string($raw)) {
                    throw new \RuntimeException('Missing return estimated plan.');
                }
                $result['returnEstimatedPlan'] = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
                NetForecastBenchmarkSupport::save($argv[2], $result);
                $sql = $q->getSQL();
                $from = strpos($sql, 'WITH ');
                $end = strrpos($sql, 'SELECT scope.marketplace_sku,');
                if (false === $from || false === $end) {
                    throw new \RuntimeException('Cannot isolate return seed.');
                }
                $prefix = substr($sql, $from, $end - $from);
                $probes = [];
                foreach (['candidate_deliveries' => 'posting_number, order_number', 'seed_keys' => 'source_row_id', 'return_keys' => 'source_row_id, fact_revision'] as $cte => $columns) {
                    $db->createSavepoint('return_probe');
                    try {
                        $raw = $db->fetchOne('EXPLAIN (ANALYZE, BUFFERS, TIMING OFF, FORMAT JSON) '.$prefix.'SELECT '.$columns.' FROM '.$cte, $q->getParameters(), $q->getParameterTypes());
                        if (!\is_string($raw)) {
                            throw new \RuntimeException('Missing return probe.');
                        }
                        $probes[$cte] = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
                    } catch (\Throwable $error) {
                        $probes[$cte] = ['failure' => $error->getMessage()];
                    } finally {
                        $db->rollbackSavepoint('return_probe');
                        $db->releaseSavepoint('return_probe');
                        $result['returnProbes'] = $probes;
                        NetForecastBenchmarkSupport::save($argv[2], $result);
                    }
                }

                return;
            }
            if (\in_array($argv[3], ['probe', 'input'], true)) {
                $sql = $query->getSQL();
                $from = strpos($sql, 'WITH ');
                $end = strrpos($sql, 'SELECT s.marketplace_sku,');
                if (false === $from || false === $end) {
                    throw new \RuntimeException('Cannot isolate target bounded CTEs.');
                }
                $prefix = substr($sql, $from, $end - $from);
                if ('input' === $argv[3]) {
                    $metrics = $db->fetchAssociative($prefix.'SELECT current_seed, historical_seed, seed_sales, seed_revisions, historical_revisions, current_order_sales, expanded_sales, sources, return_links, group_count, revisions, statuses, receipts, source_revisions, sources_per_group, statuses_per_group, groups_per_day FROM input_metrics', $query->getParameters(), $query->getParameterTypes());
                    if (false === $metrics) {
                        throw new \RuntimeException('Missing bounded input metrics.');
                    }
                    $result['inputMetrics'] = $metrics;

                    return;
                }
                $probes = [];
                foreach (['input_metrics' => 'current_seed, historical_seed, seed_sales, seed_revisions, historical_revisions, current_order_sales, expanded_sales, sources, return_links, group_count, revisions, statuses, receipts, source_revisions, sources_per_group, statuses_per_group, groups_per_day', 'sales_chain' => 'source_row_id, valid', 'return_chain' => 'source_row_id, valid', 'posting_proof' => 'posting_number, valid', 'target_closure_proof' => 'valid', 'target_source_proof' => 'source_row_id, history_valid, postings_complete', 'target_readiness' => 'source_row_id, daily_complete, deep_completed_at', 'target_output_budget' => 'bytes, allocation, scalar_slots, containers, dto_nodes, ok, input, observed, maximum'] as $cte => $columns) {
                    $db->createSavepoint('probe');
                    try {
                        $raw = $db->fetchOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$prefix.'SELECT '.$columns.' FROM '.$cte, $query->getParameters(), $query->getParameterTypes());
                        if (!\is_string($raw)) {
                            throw new \RuntimeException('Missing probe plan.');
                        }
                        $probes[$cte] = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
                    } catch (\Throwable $error) {
                        $probes[$cte] = ['failure' => $error->getMessage()];
                    } finally {
                        $db->rollbackSavepoint('probe');
                        $db->releaseSavepoint('probe');
                        $result['probes'] = $probes;
                        NetForecastBenchmarkSupport::save($argv[2], $result);
                        echo 'probe '.$cte."\n";
                    }
                }

                return;
            }
            if (\in_array($argv[3], ['explain', 'plan', 'diagnostic', 'diagnostic-off', 'target-diagnostic', 'target-diagnostic-off'], true)) {
                if (\in_array($argv[3], ['diagnostic', 'diagnostic-off', 'target-diagnostic', 'target-diagnostic-off'], true)) {
                    $db->executeStatement("SET LOCAL statement_timeout = '30s'");
                    $result['diagnosticOnly'] = true;
                }
                $plans = [];
                foreach (['gross' => new GrossTrainingAggregateQuery($db), 'returns' => new ReturnTrainingAggregateQuery($db), 'target' => $targetQuery] as $name => $populationQuery) {
                    if (\in_array($argv[3], ['target-diagnostic', 'target-diagnostic-off'], true) && 'target' !== $name) {
                        continue;
                    }
                    $q = $populationQuery instanceof NetTargetQuery ? $query : $populationQuery->build($company, $account, $context, new NetTrainingRequest($request->marketplaceSkus));
                    $raw = $db->fetchOne(('plan' === $argv[3] ? 'EXPLAIN (FORMAT JSON) ' : (\in_array($argv[3], ['explain', 'diagnostic-off', 'target-diagnostic-off'], true) ? 'EXPLAIN (ANALYZE, BUFFERS, TIMING OFF, FORMAT JSON) ' : 'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) ')).$q->getSQL(), $q->getParameters(), $q->getParameterTypes());
                    if (!\is_string($raw)) {
                        throw new \RuntimeException('Missing EXPLAIN JSON.');
                    }
                    $plans[$name] = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
                    $result['plans'] = $plans;
                    NetForecastBenchmarkSupport::save($argv[2], $result);
                }

                return;
            }
            if ('envelope-diagnostic' === $argv[3]) {
                $db->executeStatement("SET LOCAL statement_timeout = '30s'");
                $result['diagnosticOnly'] = true;
            }
            $training = null;
            if (\in_array($argv[3], ['stage', 'stage-profile'], true)) {
                $t = hrtime(true);
                $training = $trainingAction($company, $account, new NetTrainingRequest($request->marketplaceSkus));
                $result['trainingMs'] = (hrtime(true) - $t) / 1000000;
                if (null !== $training->context && $training->context->snapshotId !== $snapshot) {
                    throw new \RuntimeException('Training lost shared snapshot.');
                }
                $result['trainingRows'] = ['gross' => $training->gross, 'returns' => $training->returns, 'reason' => $training->unavailableReason];
            }
            $allocationBaseline = memory_get_usage(true);
            $t = hrtime(true);
            $rows = $query->executeQuery()->fetchAllAssociative();
            $result['targetSqlAndTransferMs'] = $targetTransferMs = (hrtime(true) - $t) / 1000000;
            $result['envelope'] = [];
            $result['rawBytes'] = 0;
            foreach ($rows as $r) {
                $envelope = $r;
                unset($envelope['days']);
                $result['envelope'][] = $envelope;
                $result['rawBytes'] += \is_string($r['days']) ? \strlen($r['days']) : 0;
            }
            $computeStarted = hrtime(true);
            $t = $computeStarted;
            $targets = $targetQuery->mapRows($context, $request, $rows);
            unset($rows, $query);
            $result['mapperMs'] = $mapperMs = (hrtime(true) - $t) / 1000000;
            $result['mappedHeapBytes'] = memory_get_usage(true);
            $result['targetReason'] = $targets->unavailableReason;
            $result['budgetFailure'] = $targets->budgetFailure;
            if (null !== $training) {
                $t = hrtime(true);
                $batch = $aggregate($company, $account, $targets, $training, $computeStarted, $allocationBaseline, $limits);
                $result['aggregateMs'] = $aggregateMs = (hrtime(true) - $t) / 1000000;
            }
        });
    }
    if (null !== $batch) {
        $result['unavailableReason'] = $batch->unavailableReason;
        $result['quantities'] = $batch->quantities;
        $digest = hash_init('sha256');
        $groups = $parts = $returns = 0;
        $selected = [];
        $seenOrders = [];
        $diagnostics = [];
        foreach ($batch->skus as $sku) {
            foreach ($sku->days as $day) {
                foreach ($day->groups as $group) {
                    ++$groups;
                    if ('normal' === $m['shape']) {
                        $order = $group->orderNumber;
                        if (null === $order || 1 !== preg_match('/^TARGET-ORDER-(0|[1-9][0-9]*)$/D', $order)) {
                            throw new \RuntimeException('Normal output lost its real order identity.');
                        }
                        $index = (int) substr($order, 13);
                        if ($index >= 2000 || isset($seenOrders[$order]) || 'BENCH-'.($index % 50) !== $group->marketplaceSku) {
                            throw new \RuntimeException('Normal output identity set differs from its fixture.');
                        }
                        $seenOrders[$order] = true;
                        foreach ($group->parts as $sourcePart) {
                            if ('TARGET-SALE-'.$index.'-0' !== $sourcePart->sourceRowId || 3 !== $sourcePart->trace->latestFactRevision
                                || (string) (10002 + 100 * ($index % 3)) !== $sourcePart->trace->amountMinor || 'RUB' !== $sourcePart->trace->currency) {
                                throw new \RuntimeException('Original source identity/price/currency/revision differs.');
                            }
                        }
                    }
                    $returns += \count($group->returns);
                    hash_update($digest, json_encode($group, \JSON_THROW_ON_ERROR));
                    foreach ($group->parts as $part) {
                        ++$parts;
                        $diagnostics[$part->unavailableReason ?? 'available'] = 1 + ($diagnostics[$part->unavailableReason ?? 'available'] ?? 0);
                        if (\count($selected) < 8 || \in_array($group->orderNumber, ['TARGET-ORDER-0', 'TARGET-ORDER-1', 'TARGET-ORDER-1000', 'TARGET-ORDER-1001', 'TARGET-ORDER-1002', 'TARGET-ORDER-1050', 'TARGET-ORDER-1100', 'TARGET-ORDER-1500'], true)) {
                            $selected[] = ['order' => $group->orderNumber, 'part' => $part];
                        }
                    }
                }
            }
        }
        $result['output'] = ['groups' => $groups, 'parts' => $parts, 'returns' => $returns, 'groupTraceQuantitySha256' => hash_final($digest), 'selected' => $selected, 'reasons' => $diagnostics];
        $result['computePeakBytes'] = $computePeakBytes = memory_get_peak_usage(true);
        $normalComplete = 'normal' === $m['shape'] && \in_array($m['state'], ['ready', 'noise_ready'], true);
        if ($normalComplete && \in_array($argv[3], ['stage', 'combined'], true)) {
            $expected = $m['expected'] ?? null;
            if (!\is_array($expected) || null !== $batch->unavailableReason || null === $batch->quantities || 2000 !== $groups || 2000 !== $parts || 1800 !== $returns) {
                throw new \RuntimeException('Required normal benchmark must produce complete2000 groups and real source traces.');
            }
            $q = $batch->quantities;
            foreach (['currentD', 'eligibleOpenQuantity'] as $field) {
                if ($q->$field !== ($expected[$field] ?? null)) {
                    throw new \RuntimeException('Exact fixture base mismatch: '.$field);
                }
            }
            foreach (['futureReturns', 'retainedOpen', 'expectedNet'] as $field) {
                $pair = $expected[$field] ?? null;
                if (!\is_array($pair) || $q->{$field.'Numerator'} !== ($pair['numerator'] ?? null) || $q->{$field.'Denominator'} !== ($pair['denominator'] ?? null)) {
                    throw new \RuntimeException('Independent exact fixture oracle mismatch: '.$field);
                }
            }
            $selectedExpected = $expected['selected'] ?? null;
            if (!\is_array($selectedExpected)) {
                throw new \RuntimeException('Missing selected exact oracle.');
            }
            $checked = 0;
            foreach ($selected as $entry) {
                $wanted = $selectedExpected[$entry['order'] ?? ''] ?? null;
                if (\is_array($wanted)) {
                    $actual = $entry['part'];
                    if (null !== $actual->unavailableReason || null === $actual->quantities || $actual->quantities->expectedNetNumerator !== ($wanted['expectedNetNumerator'] ?? null)
                        || $actual->quantities->expectedNetDenominator !== ($wanted['expectedNetDenominator'] ?? null)
                        || 3 !== $actual->trace->latestFactRevision || 'RUB' !== $actual->trace->currency || null === $actual->trace->firstRevisionId || null === $actual->trace->latestRevisionId) {
                        throw new \RuntimeException('Selected exact quantity/real trace mismatch.');
                    }
                    ++$checked;
                }
            }
            if (\count($selectedExpected) !== $checked) {
                throw new \RuntimeException('Selected exact oracle was not fully observed.');
            }
            foreach ($db->measurements as $queryMeasurement) {
                if ($queryMeasurement['milliseconds'] > 5000) {
                    throw new \RuntimeException('A normal population SQL exceeded5s.');
                }
            }
            if (3 !== \count($db->measurements) || $computePeakBytes > 100663296
                || $computePeakBytes - $baselineBytes > 50331648
                || $targetTransferMs > 5000
                || $mapperMs + $aggregateMs > 5000) {
                throw new \RuntimeException('A required normal runtime/heap/transfer gate failed.');
            }
            $result['normalGatesPassed'] = true;
        }
        // Separate serialization from the measured compute; no retained previous output string.
        $serialized = json_encode($batch, \JSON_THROW_ON_ERROR);
        $result['returnedBytes'] = \strlen($serialized);
        if ($result['returnedBytes'] > 33554432 || memory_get_peak_usage(true) > 100663296) {
            throw new \RuntimeException('Returned JSON or total process peak exceeded its unchanged cap.');
        }
        unset($serialized, $batch);
    }
    $result['status'] = true === ($result['normalGatesPassed'] ?? false) ? 'complete' : 'measured';
} catch (\Throwable $error) {
    $failed = true;
    $result['status'] = 'failed';
    $result['failure'] = ['class' => $error::class, 'message' => $error->getMessage()];
    fwrite(\STDERR, $error->getMessage()."\n");
} finally {
    $result['totalMs'] = (hrtime(true) - $started) / 1000000;
    $result['queries'] = $db->measurements;
    $result['peakBytes'] = memory_get_peak_usage(true);
    $rss = file_get_contents('/proc/self/status');
    $rssLines = false === $rss ? false : preg_grep('/^Vm(?:Peak|HWM|RSS):/', explode("\n", $rss));
    $result['osStatus'] = false === $rssLines ? null : array_values($rssLines);
    NetForecastBenchmarkSupport::save($argv[2], $result);
    if ($db->isTransactionActive()) {
        $db->rollBack();
    }
    $db->close();
    $kernel->shutdown();
}
echo json_encode(['mode' => $argv[3], 'status' => $result['status'], 'totalMs' => $result['totalMs'], 'peakBytes' => $result['peakBytes'], 'reason' => $result['unavailableReason'] ?? $result['targetReason'] ?? null], \JSON_THROW_ON_ERROR)."\n";
exit($failed ? 1 : 0);
