<?php

declare(strict_types=1);

use App\Ingestion\Domain\ValueObject\NetForecastCaptureEpoch;
use App\Ingestion\Infrastructure\Query\NetForecast\GrossTrainingAggregateQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingRequest;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAggregateQuery;
use App\Kernel;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 3).'/vendor/autoload.php';
if ('test' !== getenv('APP_ENV')) {
    throw new RuntimeException('Measurement requires explicit APP_ENV=test.');
}
(new Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
$input = json_decode(stream_get_contents(\STDIN), true, 8, \JSON_THROW_ON_ERROR);
if (!is_array($input) || !is_string($input['companyId'] ?? null) || !Uuid::isValid($input['companyId'])
    || !is_string($input['accountId'] ?? null) || !Uuid::isValid($input['accountId'])
    || !is_string($input['epochId'] ?? null) || !is_string($input['epochStart'] ?? null)
    || !is_string($input['readAt'] ?? null) || !is_string($input['database'] ?? null)
    || 'TARGET' !== ($input['marker'] ?? null) || !isset($argv[1], $argv[2])) {
    throw new RuntimeException('Invalid synthetic measurement context.');
}
$kernel = new Kernel('test', false);
$kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
if (!$registry instanceof ManagerRegistry || !($db = $registry->getConnection()) instanceof Connection) {
    throw new RuntimeException('Missing measurement connection.');
}
$database = $db->fetchOne('SELECT current_database()');
if (!is_string($database) || $database !== $input['database'] || 1 !== preg_match('/^app_test(?:[1-9][0-9]*)?$/D', $database) || $db->isTransactionActive()
    || false !== $db->fetchOne('SELECT id FROM company WHERE id = ?', [$input['companyId']])
    || 'TARGET-0' !== $db->fetchOne('SELECT source_row_id FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?', [$input['companyId'], $input['accountId'], 'TARGET-0'])) {
    throw new RuntimeException('Measurement refuses a nonmatching synthetic fixture.');
}
$diagnostic = in_array('--diagnostic', $argv, true);
$disableNestedLoops = in_array('--no-nestloop', $argv, true);
$result = ['label' => $argv[2], 'database' => $database, 'fixture' => $input, 'status' => 'running', 'diagnosticOnly' => $diagnostic, 'nestedLoopsDisabled' => $disableNestedLoops, 'queries' => []];
foreach (['GrossTrainingAggregateQuery.php', 'ReturnTrainingAggregateQuery.php', 'NetTrainingSourceSql.php', 'NetTrainingOutcomeSql.php'] as $sourceFile) {
    $result['sourceHashes'][$sourceFile] = hash_file('sha256', dirname(__DIR__, 3).'/src/Ingestion/Infrastructure/Query/NetForecast/'.$sourceFile);
}
file_put_contents($argv[1], json_encode($result, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
$failed = false;
try {
    $db->beginTransaction();
    $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $snapshot = $db->fetchOne('SELECT txid_current_snapshot()::text');
    if (!is_string($snapshot)) {
        throw new RuntimeException('Missing measurement snapshot.');
    }
    $db->executeStatement('SET LOCAL jit = off');
    if ($disableNestedLoops) {
        $db->executeStatement('SET LOCAL enable_nestloop = off');
    }
    $db->executeStatement($diagnostic ? "SET LOCAL statement_timeout = '120s'" : "SET LOCAL statement_timeout = '5s'");
    $epoch = new NetForecastCaptureEpoch($input['epochId'], 'accepted_state_v1', new DateTimeImmutable($input['epochStart']), null, 'synthetic-benchmark');
    $context = new NetTrainingContext($input['companyId'], $input['accountId'], new DateTimeImmutable($input['readAt']), $epoch, $snapshot);
    $request = new NetTrainingRequest(array_map(static fn (int $i): string => 'BENCH-'.$i, range(0, 49)));
    foreach (['gross' => new GrossTrainingAggregateQuery($db), 'returns' => new ReturnTrainingAggregateQuery($db)] as $population => $query) {
        $result['activePopulation'] = $population;
        file_put_contents($argv[1], json_encode($result, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
        echo json_encode(['case' => $argv[2], 'population' => $population, 'phase' => $diagnostic ? 'diagnostic_explain' : 'plain_query_then_explain_timing_off', 'diagnosticOnly' => $diagnostic], \JSON_THROW_ON_ERROR)."\n";
        $built = $query->build($context->companyId, $context->accountId, $context, $request);
        if (!$diagnostic) {
            $queryStarted = hrtime(true);
            $rows = $built->executeQuery()->fetchAllAssociative();
            $result['queries'][$population]['plainQueryMs'] = intdiv(hrtime(true) - $queryStarted, 1000000);
            $mapped = $query instanceof GrossTrainingAggregateQuery ? array_map(GrossTrainingAggregateQuery::mapRow(...), $rows) : array_map(ReturnTrainingAggregateQuery::mapRow(...), $rows);
            if (51 !== count($mapped) || !$mapped[0]->inputComplete || 200000 !== $mapped[0]->sampleQuantity || 20000 !== $mapped[0]->samplePostings) {
                throw new RuntimeException('Measurement requires the complete valid sample.');
            }
            foreach (array_slice($mapped, 1) as $scope) {
                if (!$scope->inputComplete || 4000 !== $scope->sampleQuantity || 400 !== $scope->samplePostings) {
                    throw new RuntimeException('Measurement requires each complete requested SKU sample.');
                }
            }
            if ($query instanceof ReturnTrainingAggregateQuery) {
                $account = ReturnTrainingAggregateQuery::mapRow($rows[0]);
                if (null === $account->ageProfile || 8000 !== $account->ageProfile[60]->returnedQuantity || 192000 !== $account->ageProfile[60]->atRiskQuantity || 20000 !== $account->ageProfile[0]->atRiskPostingsLowerBound) {
                    throw new RuntimeException('Measurement requires the exact nonzero return profile.');
                }
            }
            $result['queries'][$population]['scopeRows'] = count($mapped);
            $result['queries'][$population]['accountQuantity'] = $mapped[0]->sampleQuantity;
            $result['queries'][$population]['accountPostings'] = $mapped[0]->samplePostings;
            file_put_contents($argv[1], json_encode($result, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
        }
        $rawPlan = $db->fetchOne(($diagnostic ? 'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) ' : 'EXPLAIN (ANALYZE, BUFFERS, TIMING OFF, FORMAT JSON) ').$built->getSQL(), $built->getParameters(), $built->getParameterTypes());
        if (!is_string($rawPlan)) {
            throw new RuntimeException('Missing measurement plan.');
        }
        $plan = json_decode($rawPlan, true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($plan) || !is_array($plan[0] ?? null) || !is_numeric($plan[0]['Execution Time'] ?? null)) {
            throw new RuntimeException('Invalid measurement plan JSON.');
        }
        $result['queries'][$population]['plan'] = $plan;
        file_put_contents($argv[1], json_encode($result, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
        echo json_encode(['case' => $argv[2], 'population' => $population, 'executionMs' => $plan[0]['Execution Time'], 'plainQueryMs' => $result['queries'][$population]['plainQueryMs'] ?? null, 'diagnosticOnly' => $diagnostic], \JSON_THROW_ON_ERROR)."\n";
    }
    $result['status'] = 'complete';
    unset($result['activePopulation']);
} catch (Throwable $error) {
    $failed = true;
    $result['status'] = 'failed';
    $result['failure'] = ['class' => $error::class, 'message' => $error->getMessage()];
    fwrite(\STDERR, $error->getMessage()."\n");
} finally {
    if ($db->isTransactionActive()) {
        $db->rollBack();
    }
    file_put_contents($argv[1], json_encode($result, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
    $kernel->shutdown();
}
exit($failed ? 1 : 0);
