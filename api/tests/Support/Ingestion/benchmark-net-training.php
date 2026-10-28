<?php

declare(strict_types=1);

use App\Ingestion\Domain\FactCaptureOrigin;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\ValueObject\NetForecastCaptureEpoch;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceDayLoadWriter;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplacePostingStatusWriter;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceReturnFactWriter;
use App\Ingestion\Infrastructure\Persistence\DoctrineSalesFactWriter;
use App\Kernel;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\MarketplaceDayLoadBuilder;
use App\Tests\Support\Builder\MarketplaceDayLoadReceiptBuilder;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 3).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');

if ('cli' !== \PHP_SAPI || 'test' !== ($_SERVER['APP_ENV'] ?? null)) {
    throw new RuntimeException('Benchmark requires explicit APP_ENV=test.');
}
$kernel = new Kernel('test', false);
$kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
if (!$registry instanceof ManagerRegistry) {
    throw new RuntimeException('Missing benchmark registry.');
}
$db = $registry->getConnection();
if (!$db instanceof Connection) {
    throw new RuntimeException('Missing benchmark DBAL connection.');
}
$database = $db->fetchOne('SELECT current_database()');
if (!is_string($database) || 1 !== preg_match('/^app_test(?:[1-9][0-9]*)?$/D', $database) || $db->isTransactionActive()) {
    throw new RuntimeException('Benchmark refuses a non-test or externally owned database.');
}
$company = Uuid::v7();
$account = Uuid::v7();
$foreign = Uuid::v7();
if (false !== $db->fetchOne('SELECT id FROM company WHERE id IN (?, ?) LIMIT 1', [$company->toRfc4122(), $foreign->toRfc4122()])) {
    throw new RuntimeException('Benchmark refuses an existing company identity.');
}
$initial = new DateTimeImmutable('tomorrow', new DateTimeZone('UTC'));
$origin = $initial->modify('+2 days');
$readAt = $initial->modify('+80 days');
$epoch = new NetForecastCaptureEpoch(Uuid::v7()->toRfc4122(), 'accepted_state_v1', $initial->modify('-1 day'), null, 'synthetic-benchmark');
$scopes = [[$company, $account], [$foreign, $account]];
$output = $argv[1] ?? dirname(__DIR__, 3).'/var/net-training-benchmark.json';
$hold = in_array('--hold', $argv, true);
$manifestFile = $output.'.fixture.json';
if (is_file($manifestFile)) {
    $previousJson = file_get_contents($manifestFile);
    $previous = false === $previousJson ? null : json_decode($previousJson, true, 8, \JSON_THROW_ON_ERROR);
    if (!is_array($previous) || 'cleaned' !== ($previous['state'] ?? null)) {
        throw new RuntimeException('Refusing to overwrite an unclean fixture manifest.');
    }
}
$manifest = ['database' => $database, 'companyId' => $company->toRfc4122(), 'accountId' => $account->toRfc4122(), 'foreignCompanyId' => $foreign->toRfc4122(), 'epochId' => $epoch->id, 'epochStart' => $epoch->start->format('c'), 'readAt' => $readAt->format('c'), 'marker' => 'TARGET', 'markers' => ['TARGET', 'FOREIGN', 'OLDER'], 'seedPhases' => [], 'state' => 'preparing'];
file_put_contents($manifestFile, json_encode($manifest, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
$result = ['database' => $database, 'targetGroups' => 20000, 'targetSalesRevisions' => 100000, 'noiseGroupsEach' => 200000, 'queries' => []];
foreach (['GrossTrainingAggregateQuery.php', 'ReturnTrainingAggregateQuery.php', 'NetTrainingSourceSql.php'] as $sourceFile) {
    $result['sourceHashes'][$sourceFile] = hash_file('sha256', dirname(__DIR__, 3).'/src/Ingestion/Infrastructure/Query/NetForecast/'.$sourceFile);
}
$receipt = static function (Uuid $owner, string $type, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $at, FactCaptureOrigin $captureOrigin) use ($db, $account): void {
    MarketplaceDayLoadReceiptBuilder::aMarketplaceDayLoadReceipt()->withLoad(MarketplaceDayLoadBuilder::aMarketplaceDayLoad()
        ->withCompanyId($owner)->withMarketplaceAccountId($account)->withReportType($type)->withRange($from, $to)->withAt($at))
        ->withOrigin($captureOrigin)->persistWith(new DoctrineMarketplaceDayLoadWriter($db));
};
$seed = static function (string $prefix, Uuid $owner, int $groups, DateTimeImmutable $businessInitial, FactCaptureOrigin $captureOrigin) use ($db, $account, $initial, $receipt, &$manifest, $manifestFile): void {
    $manifest['state'] = 'seeding_'.$prefix;
    $started = hrtime(true);
    $businessDay = new DateTimeImmutable($businessInitial->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d'));
    $states = [['awaiting_packaging', '+0 hours'], ['awaiting_packaging', '+6 hours'], ['awaiting_deliver', '+12 hours'], ['delivering', '+1 day'], ['delivered', '+2 days']];
    foreach ($states as $phase => [$status, $offset]) {
        $captured = $initial->modify($offset);
        $observed = $businessInitial->modify($offset);
        $writer = new DoctrineSalesFactWriter($db, new MockClock($captured));
        for ($from = 0; $from < $groups; $from += 500) {
            $facts = [];
            $statuses = [];
            $raw = Uuid::v7();
            for ($i = $from; $i < min($groups, $from + 500); ++$i) {
                $key = $prefix.'-'.$i;
                $facts[] = SalesFactBuilder::aSalesFact()->withCompanyId($owner)->withMarketplaceAccountId($account)->withSourceRowId($key)
                    ->withPostingNumber($key)->withOrderNumber($key)->withMarketplaceSku('BENCH-'.($i % 50))->withBusinessDate($businessDay)
                    ->withOrderedAt($businessInitial)->withStatus($status)->withQuantity(10)->withAmount(Money::ofMinor(10000 + $phase, 'RUB'))
                    ->withCommissionAmount(Money::ofMinor(-100, 'RUB'))->withRawDocumentId($raw)->build();
                $statuses[] = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($owner)->withMarketplaceAccountId($account)
                    ->withPostingNumber($key)->withOrderNumber($key)->withStatus($status)->withObservedAt($observed)->withRawDocumentId($raw)->build();
            }
            $writer->upsertAll($facts, $captureOrigin);
            (new DoctrineMarketplacePostingStatusWriter($db))->recordChanged($owner->toRfc4122(), $statuses);
        }
        $receipt($owner, MarketplaceReportType::OzonPostingFboList, $businessDay, $businessDay, $captured->modify('+1 second'), $captureOrigin);
        $manifest['seedPhases'][$prefix] = $phase + 1;
        file_put_contents($manifestFile, json_encode($manifest, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
        echo json_encode(['seed' => $prefix, 'phase' => $phase + 1, 'groups' => $groups, 'elapsedMs' => intdiv(hrtime(true) - $started, 1000000)], \JSON_THROW_ON_ERROR)."\n";
    }
};
$runWorker = static function (string $label, bool $diagnosticOnly = false, bool $noNestloop = false) use (&$manifest, $output, &$result): bool {
    $artifact = $output.'.'.$label.'.json';
    $command = [\PHP_BINARY, __DIR__.'/measure-net-training.php', $artifact, $label];
    if ($diagnosticOnly) {
        $command[] = '--diagnostic';
    }
    if ($noNestloop) {
        $command[] = '--no-nestloop';
    }
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => \STDOUT, 2 => \STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start fresh measurement worker.');
    }
    fwrite($pipes[0], json_encode($manifest, \JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $exitCode = proc_close($process);
    $json = file_get_contents($artifact);
    $measurement = false === $json ? null : json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    if (!is_array($measurement)) {
        throw new RuntimeException('Missing fresh measurement artifact.');
    }
    $result['queries'][$label] = $measurement;

    return 0 === $exitCode && 'complete' === ($measurement['status'] ?? null);
};
$measure = static function (string $label) use ($runWorker, $hold, &$manifest, $manifestFile): void {
    for ($attempt = 1;; ++$attempt) {
        $manifest['state'] = 'measuring_'.$label;
        file_put_contents($manifestFile, json_encode($manifest, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
        if ($runWorker($label.'-attempt'.$attempt)) {
            $manifest['state'] = 'measured_'.$label;
            file_put_contents($manifestFile, json_encode($manifest, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));

            return;
        }
        if (!$runWorker($label.'-alternative-planner'.$attempt, false, true)) {
            $runWorker($label.'-diagnostic'.$attempt, true);
            $runWorker($label.'-alternative-diagnostic'.$attempt, true, true);
        }
        $manifest['state'] = 'waiting_after_failed_measure';
        file_put_contents($manifestFile, json_encode($manifest, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
        if (!$hold) {
            throw new RuntimeException('Measurement failed its unchanged 5s gate.');
        }
        echo "Fixture held after failed measurement. Internal commands: retry / cleanup.\n";
        $command = fgets(\STDIN);
        if (false === $command || 'retry' !== trim($command)) {
            throw new RuntimeException('Failed measurement fixture released for cleanup.');
        }
    }
};
try {
    $seed('TARGET', $company, 20000, $initial, FactCaptureOrigin::LivePolling);
    for ($phase = 0; $phase < 2; ++$phase) {
        for ($age = 1; $age <= 59; ++$age) {
            $at = $origin->modify('+'.min(59, $age + 10 * $phase).' days');
            $facts = [];
            $raw = Uuid::v7();
            for ($i = 0; $i < 20000; $i += 5) {
                if (1 + $i % 59 !== $age) {
                    continue;
                }
                $facts[] = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()->withCompanyId($company)->withMarketplaceAccountId($account)
                    ->withSourceRowId('RET-TARGET-'.$i)->withOrderNumber('TARGET-'.$i)->withMarketplaceSku('BENCH-'.($i % 50))
                    ->withReturnType('ClientReturn')->withQuantity($phase + 1)->withRawDocumentId($raw)->withVisualStatus(1, 'Completed', $at)->build();
            }
            (new DoctrineMarketplaceReturnFactWriter($db, new MockClock($at)))->upsertAll($facts, FactCaptureOrigin::LivePolling);
        }
    }
    $first = $origin->setTimezone(new DateTimeZone('Europe/Moscow'))->setTime(0, 0);
    for ($i = 0; $i <= 67; ++$i) {
        $day = $first->modify('+'.$i.' days');
        $receipt($company, MarketplaceReportType::OzonReturnsList, $day, $day, $day->modify('+1 day +1 hour'), FactCaptureOrigin::LivePolling);
    }
    $receipt($company, MarketplaceReportType::OzonReturnsList, $first, $first->modify('+60 days'), $origin->modify('+68 days'), FactCaptureOrigin::LivePolling);
    foreach (['sales_fact', 'sales_fact_revision', 'marketplace_return_fact', 'marketplace_return_fact_revision', 'marketplace_posting_status', 'marketplace_day_load_receipt'] as $table) {
        $db->executeStatement('ANALYZE '.$table);
    }
    $measure('target');
    $seed('FOREIGN', $foreign, 200000, $initial, FactCaptureOrigin::LivePolling);
    $seed('OLDER', $company, 200000, $initial->modify('-365 days'), FactCaptureOrigin::HistoricalBackfill);
    foreach (['sales_fact', 'sales_fact_revision', 'marketplace_return_fact', 'marketplace_return_fact_revision', 'marketplace_posting_status', 'marketplace_day_load_receipt'] as $table) {
        $db->executeStatement('ANALYZE '.$table);
    }
    $measure('foreign-and-older-10x');
    $manifest['state'] = 'measurements_complete';
    file_put_contents($manifestFile, json_encode($manifest, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
    while ($hold) {
        echo "Complete fixture held. Internal commands: recheck / cleanup.\n";
        $command = fgets(\STDIN);
        if (false === $command || 'cleanup' === trim($command)) {
            break;
        }
        if ('recheck' === trim($command)) {
            $measure('foreign-and-older-10x-recheck');
        }
    }
} catch (Throwable $error) {
    $result['failure'] = ['class' => $error::class, 'message' => $error->getMessage()];
    throw $error;
} finally {
    file_put_contents($output, json_encode($result, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
    foreach ($scopes as [$owner, $scopeAccount]) {
        foreach (['sales_fact_revision', 'marketplace_return_fact_revision', 'marketplace_day_load_receipt', 'marketplace_posting_status', 'marketplace_day_load', 'marketplace_return_fact', 'sales_fact'] as $table) {
            $db->executeStatement('DELETE FROM '.$table.' WHERE company_id = ? AND marketplace_account_id = ?', [$owner->toRfc4122(), $scopeAccount->toRfc4122()]);
        }
    }
    $manifest['state'] = 'cleaned';
    file_put_contents($manifestFile, json_encode($manifest, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
    $kernel->shutdown();
    echo "Benchmark own synthetic scopes cleaned.\n";
}
