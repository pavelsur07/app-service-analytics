<?php

declare(strict_types=1);

namespace App\Tests\Support\Ingestion;

use App\Ingestion\Domain\FactCaptureOrigin;
use App\Ingestion\Domain\MarketplaceReportType;
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
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

require_once \dirname(__DIR__, 3).'/vendor/autoload.php';

/** Noise-only normalization-clock adapter; no ledger DML or primary fixture overrides. */
final class NetForecastOlderNoiseConnection extends Connection
{
    private string $owner = '';
    private string $account = '';

    public function scope(string $companyId, string $accountId, string $database): void
    {
        if ('test' !== getenv('APP_ENV') || $database !== $this->fetchOne('SELECT current_database()')
            || 1 !== preg_match('/^app_test(?:[1-9][0-9]*)?$/D', $database)
            || false !== $this->fetchOne('SELECT id FROM company WHERE id = ?', [$companyId])
            || 'TARGET-SALE-0-0' !== $this->fetchOne('SELECT source_row_id FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?', [$companyId, $accountId, 'TARGET-SALE-0-0'])) {
            throw new \RuntimeException('Older noise clock requires its own retained synthetic test scope.');
        }
        $this->owner = $companyId;
        $this->account = $accountId;
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        foreach ($params as $name => $value) {
            if (!\is_string($name) || 1 !== preg_match('/^(?:firstLoadedAt|lastUpdatedAt)([0-9]+)$/D', $name, $match)) {
                continue;
            }
            $index = $match[1];
            $source = $params['sourceRowId'.$index] ?? null;
            if ('' === $this->owner || ($params['companyId'.$index] ?? null) !== $this->owner
                || ($params['marketplaceAccountId'.$index] ?? $params['accountId'.$index] ?? null) !== $this->account
                || !\is_string($source) || !str_starts_with($source, 'OLDER-') || !\is_string($value)) {
                throw new \RuntimeException('Older normalization clock refuses a foreign/unlabelled fact.');
            }
            $params[$name] = (new \DateTimeImmutable($value))->modify('-365 days')->format('Y-m-d H:i:sP');
        }

        return parent::executeStatement($sql, $params, $types);
    }
}

/** Test-only lifecycle; accepted revisions are produced by real writers in bounded batches. */
final class NetForecastBenchmarkSupport
{
    public const string NORMAL_EXPECTED_JSON = '{"currentD":"31896","eligibleOpenQuantity":"4000","futureReturns":{"numerator":"90049915903451189877312980701285407015358122867509862855966747443475252743280505478104927544988151865620750715204500739672224909601868335044829226453018756624128570112402350394656525656367663583058456765257890493159056684602407269","denominator":"76846074827512823552029801996665089328525065831461390895840337386788616132417513037848609354051109081031419738875005768485676920506406216433353941731607770612040715187317724800579042151092946365288745571353956743010275023846800"},"retainedOpen":{"numerator":"84548","denominator":"25"},"expectedNet":{"numerator":"2620919764175559998365310211752905881109202867529518568256217007660662604086853926070075285358478550206279192319248842760504006637869489655793716461639301646405794817008577359702186877026517210855888686401321287170217984784663095387","denominator":"76846074827512823552029801996665089328525065831461390895840337386788616132417513037848609354051109081031419738875005768485676920506406216433353941731607770612040715187317724800579042151092946365288745571353956743010275023846800"},"trainingReturnSampleQuantity":199440,"trainingReturnSamplePostings":9972,"trainingReturnedH60":30344,"ageProfileReturned":[0,516,1032,1548,2064,2580,3096,3612,4128,4644,5160,5661,6162,6663,7164,7665,8166,8668,9169,9670,10171,10671,11171,11671,12172,12672,13172,13672,14172,14672,15172,15688,16204,16720,17236,17752,18268,18784,19300,19816,20332,20833,21334,21835,22336,22837,23338,23840,24341,24842,25343,25843,26343,26843,27344,27844,28344,28844,29344,29844,30344],"selected":{"TARGET-ORDER-0":{"expectedNetNumerator":"0","expectedNetDenominator":"1"},"TARGET-ORDER-1":{"expectedNetNumerator":"18","expectedNetDenominator":"1"},"TARGET-ORDER-1000":{"expectedNetNumerator":"21137","expectedNetDenominator":"1250"},"TARGET-ORDER-1001":{"expectedNetNumerator":"21137","expectedNetDenominator":"1250"},"TARGET-ORDER-1002":{"expectedNetNumerator":"84548","expectedNetDenominator":"5341"},"TARGET-ORDER-1050":{"expectedNetNumerator":"21137","expectedNetDenominator":"1250"},"TARGET-ORDER-1100":{"expectedNetNumerator":"21137","expectedNetDenominator":"1250"},"TARGET-ORDER-1500":{"expectedNetNumerator":"21137","expectedNetDenominator":"1250"}}}';

    public const array TABLES = ['sales_fact_revision', 'marketplace_return_fact_revision', 'marketplace_day_load_receipt', 'marketplace_posting_status', 'marketplace_day_load', 'marketplace_return_fact', 'sales_fact'];

    /** @return array{Kernel, Connection, string} */
    public static function boot(): array
    {
        if ('cli' !== \PHP_SAPI || 'test' !== getenv('APP_ENV')) {
            throw new \RuntimeException('Explicit APP_ENV=test is required.');
        }
        (new Dotenv())->bootEnv(\dirname(__DIR__, 3).'/.env');
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $registry = $kernel->getContainer()->get('doctrine');
        if (!$registry instanceof ManagerRegistry || !($db = $registry->getConnection()) instanceof Connection) {
            throw new \RuntimeException('Missing test connection.');
        }
        $database = $db->fetchOne('SELECT current_database()');
        if (!\is_string($database) || 1 !== preg_match('/^app_test(?:[1-9][0-9]*)?$/D', $database) || $db->isTransactionActive()) {
            throw new \RuntimeException('Refusing non-test or externally owned transaction.');
        }

        return [$kernel, $db, $database];
    }

    /** @return array<string, mixed> */
    public static function manifest(string $path): array
    {
        $json = file_get_contents($path);
        $m = false === $json ? null : json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        if (!\is_array($m) || 'B09-TASK5-SYNTHETIC' !== ($m['marker'] ?? null)) {
            throw new \RuntimeException('Missing labelled fixture manifest.');
        }
        foreach (['companyId', 'accountId', 'foreignCompanyId', 'epochId'] as $key) {
            if (!\is_string($m[$key] ?? null) || !Uuid::isValid($m[$key])) {
                throw new \RuntimeException('Invalid fixture UUID.');
            }
        }
        foreach (['database', 'readAt', 'epochStart', 'state', 'shape'] as $key) {
            if (!\is_string($m[$key] ?? null)) {
                throw new \RuntimeException('Invalid fixture context.');
            }
        }

        return $m;
    }

    /** @param array<string, mixed> $m */
    public static function verify(Connection $db, string $database, array $m): void
    {
        if ($database !== $m['database'] || 'cleaned' === $m['state']
            || false !== $db->fetchOne('SELECT id FROM company WHERE id IN (?, ?) LIMIT 1', [$m['companyId'], $m['foreignCompanyId']])) {
            throw new \RuntimeException('Fixture database/ownership mismatch.');
        }
        if ('seeding' !== $m['state'] && 'TARGET-SALE-0-0' !== $db->fetchOne('SELECT source_row_id FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?', [$m['companyId'], $m['accountId'], 'TARGET-SALE-0-0'])) {
            throw new \RuntimeException('Missing own synthetic source marker.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function save(string $path, array $data): void
    {
        if (false === file_put_contents($path, json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES))) {
            throw new \RuntimeException('Cannot write measurement artifact.');
        }
    }

    /** @param array<string, mixed> $m
     * @return array<string, int>
     */
    public static function cleanup(Connection $db, array $m, string $mode): array
    {
        if (!\in_array($mode, ['all', 'noise', 'older'], true) || !\is_string($m['readAt'])) {
            throw new \RuntimeException('Invalid synthetic cleanup mode.');
        }
        $plans = [];
        $owners = 'older' === $mode ? [] : ['foreign' => $m['foreignCompanyId']];
        if ('all' === $mode) {
            $owners['primary'] = $m['companyId'];
        }
        foreach ($owners as $label => $owner) {
            foreach (self::TABLES as $table) {
                $plans[$label.':'.$table] = [$table, 'TRUE', [$owner, $m['accountId']]];
            }
        }
        if ('all' !== $mode) {
            $at = new \DateTimeImmutable($m['readAt']);
            foreach (self::TABLES as $table) {
                $where = match ($table) {
                    'marketplace_posting_status' => "posting_number LIKE 'OLDER-%'",
                    'marketplace_day_load' => "report_type = 'ozon_posting_fbo_list' AND period >= ? AND period <= ?",
                    'marketplace_day_load_receipt' => "report_type = 'ozon_posting_fbo_list' AND date_from >= ? AND date_to <= ?",
                    default => "source_row_id LIKE 'OLDER-%'",
                };
                $values = [$m['companyId'], $m['accountId']];
                if (\in_array($table, ['marketplace_day_load', 'marketplace_day_load_receipt'], true)) {
                    $values[] = $at->modify('-447 days')->format('Y-m-d');
                    $values[] = $at->modify('-367 days')->format('Y-m-d');
                }
                $plans['older:'.$table] = [$table, $where, $values];
            }
        }

        return $db->transactional(static function (Connection $transaction) use ($plans): array {
            // Compile ALL actual columns/binds before the first mutation.
            foreach ($plans as [$table, $where, $values]) {
                $transaction->fetchOne('SELECT 1 FROM '.$table.' WHERE company_id = ? AND marketplace_account_id = ? AND '.$where.' LIMIT 0', $values);
            }
            $deleted = [];
            foreach ($plans as $key => [$table, $where, $values]) {
                $deleted[$key] = (int) $transaction->executeStatement('DELETE FROM '.$table.' WHERE company_id = ? AND marketplace_account_id = ? AND '.$where, $values);
                if (false !== $transaction->fetchOne('SELECT 1 FROM '.$table.' WHERE company_id = ? AND marketplace_account_id = ? AND '.$where.' LIMIT 1', $values)) {
                    throw new \RuntimeException('Own synthetic cleanup incomplete.');
                }
            }

            return $deleted;
        });
    }

    public static function receipt(Connection $db, Uuid $owner, Uuid $account, string $report, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $at, FactCaptureOrigin $origin = FactCaptureOrigin::LivePolling): void
    {
        MarketplaceDayLoadReceiptBuilder::aMarketplaceDayLoadReceipt()->withLoad(MarketplaceDayLoadBuilder::aMarketplaceDayLoad()
            ->withCompanyId($owner)->withMarketplaceAccountId($account)->withReportType($report)->withRange($from, $to)->withAt($at))
            ->withOrigin($origin)->persistWith(new DoctrineMarketplaceDayLoadWriter($db));
    }

    /** @param array<string, mixed> $m */
    public static function seed(Connection $db, array $m, string $prefix, bool $foreign = false, bool $older = false): void
    {
        if (!\is_string($m['companyId']) || !\is_string($m['foreignCompanyId']) || !\is_string($m['accountId']) || !\is_string($m['readAt']) || !\is_string($m['shape'])) {
            throw new \RuntimeException('Invalid seed identity.');
        }
        if ($older) {
            if ('OLDER' !== $prefix || !\is_string($m['database'])) {
                throw new \RuntimeException('Invalid older clock scope.');
            }
            $parameters = $db->getParams();
            $parameters['wrapperClass'] = NetForecastOlderNoiseConnection::class;
            $olderDb = DriverManager::getConnection($parameters);
            if (!$olderDb instanceof NetForecastOlderNoiseConnection) {
                throw new \RuntimeException('Missing guarded older normalization clock.');
            }
            $olderDb->scope($m['companyId'], $m['accountId'], $m['database']);
            $db = $olderDb;
        }
        $owner = Uuid::fromString($foreign ? $m['foreignCompanyId'] : $m['companyId']);
        $account = Uuid::fromString($m['accountId']);
        $readAt = new \DateTimeImmutable($m['readAt']);
        $fromDay = new \DateTimeImmutable($readAt->modify('-184 days')->format('Y-m-d'));
        $toDay = new \DateTimeImmutable($readAt->format('Y-m-d'));
        $originKind = $older ? FactCaptureOrigin::HistoricalBackfill : FactCaptureOrigin::LivePolling;
        $orders = 'TARGET' === $prefix ? 2000 : 20000;
        $siblings = str_starts_with($m['shape'], 'stress') && 'TARGET' === $prefix ? 1 : 10;
        if (1 === $siblings) {
            $orders = 20000;
        }
        $started = hrtime(true);
        // At most 500 facts exist at once. Each phase changes content and raw identity.
        for ($phase = 0; $phase < 3; ++$phase) {
            for ($ageBin = 0; $ageBin <= 60; ++$ageBin) {
                $age = 60 === $ageBin ? 80 : $ageBin;
                $origin = $readAt->modify('-'.$age.' days -12 hours');
                if ($older) {
                    $origin = $origin->modify('-365 days');
                }
                $captured = $origin->modify('-'.(2 - $phase).' hours');
                $day = new \DateTimeImmutable($origin->modify('-2 days')->format('Y-m-d'));
                $facts = [];
                $statuses = [];
                $flush = static function () use ($db, &$facts, &$statuses, $captured, $originKind, $owner): void {
                    (new DoctrineSalesFactWriter($db, new MockClock($captured)))->upsertAll($facts, $originKind);
                    (new DoctrineMarketplacePostingStatusWriter($db))->recordChanged($owner->toRfc4122(), $statuses);
                    $facts = $statuses = [];
                };
                for ($i = 0; $i < $orders; ++$i) {
                    $bin = $i < intdiv($orders, 2) ? 60 : (intdiv($i - intdiv($orders, 2), 50) + 37 * ($i % 50)) % 60;
                    if ($bin !== $ageBin) {
                        continue;
                    }
                    $order = $prefix.'-ORDER-'.$i;
                    for ($j = 0; $j < $siblings; ++$j) {
                        $key = $prefix.'-SALE-'.$i.'-'.$j;
                        $sku = 0 === $j ? 'BENCH-'.($i % 50) : 'SIB-'.$j.'-'.($i % 50);
                        $pre = 0 === $j && $i >= intdiv($orders, 2) && 0 === $i % 10;
                        $post = 0 === $j && $i >= intdiv($orders, 2) && 1 === $i % 10;
                        $cancel = 0 === $j && $i < intdiv($orders, 2) && 0 === $i % 37;
                        $status = match (true) {
                            $pre => 'awaiting_packaging',
                            0 === $phase => 'awaiting_packaging',
                            1 === $phase || $post => 'delivering',
                            $cancel => 'cancelled',
                            default => 'delivered',
                        };
                        $raw = Uuid::v7();
                        $facts[] = SalesFactBuilder::aSalesFact()->withCompanyId($owner)->withMarketplaceAccountId($account)
                            ->withSourceRowId($key)->withOrderNumber($order)->withPostingNumber($key)->withMarketplaceSku($sku)
                            ->withQuantity(20)->withStatus($status)->withBusinessDate($day)->withOrderedAt($origin->modify('-2 days'))
                            ->withAmount(Money::ofMinor(10000 + 100 * ($i % 3) + $phase, 'RUB'))->withCommissionAmount(Money::ofMinor(-100, 'RUB'))->withRawDocumentId($raw)->build();
                        $statuses[] = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($owner)->withMarketplaceAccountId($account)
                            ->withPostingNumber($key)->withOrderNumber($order)->withStatus($status)->withRawDocumentId($raw)->withObservedAt($captured)->build();
                        if (500 === \count($facts)) {
                            $flush();
                        }
                    }
                }
                $flush();
                // Accepted, actual polling receipts before known->delivered transitions.
                self::receipt($db, $owner, $account, MarketplaceReportType::OzonPostingFboList, $day, $day, $captured->modify('+1 second'), $originKind);
            }
            echo json_encode(['seed' => $prefix, 'phase' => $phase + 1, 'elapsedMs' => intdiv(hrtime(true) - $started, 1000000), 'peakBytes' => memory_get_peak_usage(true)], \JSON_THROW_ON_ERROR)."\n";
        }
        // 15 returns/order minus 200 open target groups plus 200 second sibling returns =30000.
        for ($revision = 1; $revision <= ('stress-groups' === $m['shape'] ? 0 : 2); ++$revision) {
            for ($ageBin = 0; $ageBin <= 60; ++$ageBin) {
                $age = 60 === $ageBin ? 80 : $ageBin;
                $origin = $readAt->modify('-'.$age.' days -12 hours');
                if ($older) {
                    $origin = $origin->modify('-365 days');
                }
                for ($eventAge = 1; $eventAge <= 30; ++$eventAge) {
                    // Fresh observations are bounded by current readAt; mature80d training has all60 real bins.
                    $at = min(($older ? $readAt->modify('-365 days') : $readAt)->modify('-2 hours'), $origin->modify('+'.($eventAge - 1 + 30 * ($revision - 1)).' days +12 hours'));
                    $facts = [];
                    $flush = static function () use ($db, &$facts, $at, $originKind): void {
                        (new DoctrineMarketplaceReturnFactWriter($db, new MockClock($at)))->upsertAll($facts, $originKind);
                        $facts = [];
                    };
                    for ($i = 0; $i < $orders; ++$i) {
                        $bin = $i < intdiv($orders, 2) ? 60 : (intdiv($i - intdiv($orders, 2), 50) + 37 * ($i % 50)) % 60;
                        if ($bin !== $ageBin || 1 + $i % 30 !== $eventAge) {
                            continue;
                        }
                        for ($j = 0; $j < $siblings; ++$j) {
                            $open = 0 === $j && $i >= intdiv($orders, 2) && $i % 10 < 2;
                            if ($open) {
                                continue;
                            }
                            $cancel = 0 === $j && $i < intdiv($orders, 2) && 0 === $i % 37;
                            $number = 1 === $siblings ? (0 === $i % 2 || $i < 6000 ? 2 : 1) : ($j >= 1 && $j <= 5 || 6 === $j && $i < intdiv($orders, 10) ? 2 : 1);
                            for ($r = 0; $r < $number; ++$r) {
                                $facts[] = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()->withCompanyId($owner)->withMarketplaceAccountId($account)
                                    ->withSourceRowId($prefix.'-RETURN-'.$i.'-'.$j.'-'.$r)->withOrderNumber($prefix.'-ORDER-'.$i)->withPostingNumber($prefix.'-SALE-'.$i.'-'.$j)
                                    ->withMarketplaceSku(0 === $j ? 'BENCH-'.($i % 50) : 'SIB-'.$j.'-'.($i % 50))
                                    ->withReturnType($cancel ? 'Cancellation' : 'ClientReturn')->withReturnReasonName($cancel ? 'Покупатель отменил заказ' : 'synthetic client return')
                                    ->withQuantity($cancel ? intdiv(20, $number) : $revision)->withRawDocumentId(Uuid::v7())->withVisualStatus(1, 'Completed', $at)->build();
                                if (500 === \count($facts)) {
                                    $flush();
                                }
                            }
                        }
                    }
                    $flush();
                }
            }
        }
        if (!$foreign && !$older) {
            self::receipt($db, $owner, $account, MarketplaceReportType::OzonPostingFboList, $fromDay, $toDay, $readAt->modify('-1 hour'));
            $first = $readAt->modify('-81 days')->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0);
            $last = $readAt->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0)->modify('-1 day');
            for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
                self::receipt($db, $owner, $account, MarketplaceReportType::OzonReturnsList, $day, $day, $day->modify('+1 day +1 hour')->setTimezone(new \DateTimeZone('UTC')));
            }
            self::receipt($db, $owner, $account, MarketplaceReportType::OzonReturnsList, $first, $readAt->modify('-20 days'), $readAt->modify('-12 days'));
            // Current-day completion deliberately omitted: RED coverage, then --ready gives GREEN.
        }
        if (isset($olderDb)) {
            $olderDb->close();
        }
        echo json_encode(['seed' => $prefix, 'doneMs' => intdiv(hrtime(true) - $started, 1000000), 'peakBytes' => memory_get_peak_usage(true)], \JSON_THROW_ON_ERROR)."\n";
    }
}

if (!\defined('NET_FORECAST_MEASURE_WORKER')) {
    [$kernel, $db, $database] = NetForecastBenchmarkSupport::boot();
    $path = $argv[1] ?? \dirname(__DIR__, 3).'/var/net-forecast.fixture.json';
    $operation = $argv[2] ?? '--seed';
    if ('--seed' === $operation) {
        if (is_file($path) && 'cleaned' !== NetForecastBenchmarkSupport::manifest($path)['state']) {
            throw new \RuntimeException('Refusing to overwrite retained fixture.');
        }
        $at = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('+200 days +12 hours');
        $m = ['marker' => 'B09-TASK5-SYNTHETIC', 'database' => $database, 'companyId' => Uuid::v7()->toRfc4122(), 'accountId' => Uuid::v7()->toRfc4122(), 'foreignCompanyId' => Uuid::v7()->toRfc4122(), 'epochId' => Uuid::v7()->toRfc4122(), 'epochStart' => $at->modify('-200 days')->setTime(0, 0)->format('Y-m-d\TH:i:s\Z'), 'readAt' => $at->format('Y-m-d\TH:i:s.uP'), 'shape' => \in_array('--stress-groups', $argv, true) ? 'stress-groups' : (\in_array('--stress', $argv, true) ? 'stress-keys' : 'normal'), 'curveProfile' => 'accepted_midbin_0_to_59_v1', 'expected' => json_decode(NetForecastBenchmarkSupport::NORMAL_EXPECTED_JSON, true, 8, \JSON_THROW_ON_ERROR), 'acceptedSalePhases' => 3, 'seedScriptSha256' => hash_file('sha256', __FILE__), 'state' => 'seeding'];
        NetForecastBenchmarkSupport::verify($db, $database, $m);
        NetForecastBenchmarkSupport::save($path, $m);
        NetForecastBenchmarkSupport::seed($db, $m, 'TARGET');
        $m['state'] = 'seeded';
    } else {
        $m = NetForecastBenchmarkSupport::manifest($path);
        NetForecastBenchmarkSupport::verify($db, $database, $m);
        if ('--ready' === $operation) {
            if (!\is_string($m['readAt']) || !\is_string($m['companyId']) || !\is_string($m['accountId'])) {
                throw new \RuntimeException('Invalid ready context.');
            }
            $at = new \DateTimeImmutable($m['readAt']);
            NetForecastBenchmarkSupport::receipt($db, Uuid::fromString($m['companyId']), Uuid::fromString($m['accountId']), MarketplaceReportType::OzonReturnsList, $at, $at, $at->modify('-1 second'));
            NetForecastBenchmarkSupport::receipt($db, Uuid::fromString($m['companyId']), Uuid::fromString($m['accountId']), MarketplaceReportType::OzonPostingFboList, $at->modify('-184 days'), $at->modify('-1 day'), $at->modify('-1 hour'));
            $m['state'] = 'ready';
        } elseif ('--audit-noise' === $operation) {
            if (!\is_string($m['readAt'])) {
                throw new \RuntimeException('Invalid audit timestamp.');
            }
            $audit = ['originFrom' => (new \DateTimeImmutable($m['readAt']))->modify('-157 days')->format('c'), 'originTo' => (new \DateTimeImmutable($m['readAt']))->modify('-67 days')->format('c')];
            foreach (['sales_fact_revision' => 'source_row_id', 'marketplace_return_fact_revision' => 'source_row_id', 'marketplace_posting_status' => 'posting_number'] as $table => $key) {
                $time = 'marketplace_posting_status' === $table ? 'observed_at' : 'captured_at';
                $extra = 'marketplace_posting_status' === $table ? '' : ', MIN(first_loaded_at)::text AS first_loaded_min, MAX(first_loaded_at)::text AS first_loaded_max';
                $audit[$table] = $db->fetchAssociative('SELECT MIN('.$time.')::text AS observation_min, MAX('.$time.')::text AS observation_max'.$extra.' FROM '.$table.' WHERE company_id = ? AND marketplace_account_id = ? AND '.$key.' LIKE ?', [$m['companyId'], $m['accountId'], 'OLDER-%']);
            }
            foreach (['sales_fact_revision', 'marketplace_return_fact_revision'] as $table) {
                if (!\is_array($audit[$table])) {
                    throw new \RuntimeException('Missing synthetic audit row.');
                }
                $audit[$table]['first_loaded_after_capture'] = $db->fetchOne('SELECT source_row_id FROM '.$table.' WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id LIKE ? AND first_loaded_at > captured_at LIMIT 1', [$m['companyId'], $m['accountId'], 'OLDER-%']);
                $audit[$table]['unstable_first_loaded'] = $db->fetchOne('SELECT source_row_id FROM '.$table.' WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id LIKE ? GROUP BY source_row_id HAVING MIN(first_loaded_at) <> MAX(first_loaded_at) LIMIT 1', [$m['companyId'], $m['accountId'], 'OLDER-%']);
            }
            $audit['older_delivery_in_training_window'] = $db->fetchOne("SELECT posting_number FROM marketplace_posting_status WHERE company_id = ? AND marketplace_account_id = ? AND posting_number LIKE 'OLDER-%' AND status = 'delivered' AND observed_at >= ? AND observed_at < ? LIMIT 1", [$m['companyId'], $m['accountId'], $audit['originFrom'], $audit['originTo']]);
            $audit['return_visual'] = $db->fetchAssociative("SELECT MIN(visual_status_changed_at)::text AS minimum, MAX(visual_status_changed_at)::text AS maximum FROM marketplace_return_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id LIKE 'OLDER-%'", [$m['companyId'], $m['accountId']]);
            foreach (['sales_fact' => 200000, 'marketplace_return_fact' => 300000] as $table => $expectedRows) {
                $counts = $db->fetchAssociative('SELECT COUNT(source_row_id)::text AS rows, SUM(fact_revision)::text AS accepted_revisions FROM (SELECT source_row_id, fact_revision FROM '.$table.' WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id LIKE ? LIMIT 500001) bounded_noise', [$m['companyId'], $m['accountId'], 'OLDER-%']);
                if (false === $counts || (string) $expectedRows !== $counts['rows']) {
                    throw new \RuntimeException('Older noise physical volume mismatch.');
                }
                $audit[$table.'_counts'] = $counts;
            }
            NetForecastBenchmarkSupport::save($path.'.noise-audit.json', $audit);
            echo json_encode($audit, \JSON_THROW_ON_ERROR)."\n";
        } elseif ('--repair-older' === $operation) {
            if (!\is_string($m['readAt'])) {
                throw new \RuntimeException('Invalid older cleanup range.');
            }
            $at = new \DateTimeImmutable($m['readAt']);
            $next = $path.'.older-corrected.json';
            if (is_file($next)) {
                throw new \RuntimeException('Refusing to overwrite a corrected noise manifest.');
            }
            $m['state'] = 'repairing_older';
            NetForecastBenchmarkSupport::save($path, $m);
            $deleted = NetForecastBenchmarkSupport::cleanup($db, $m, 'older');
            $m['olderCleanupProgress'] = $deleted;
            $previousHash = hash_file('sha256', $path);
            $m['state'] = 'superseded_older_wrong_cleaned';
            $m['olderDeletedRows'] = $deleted;
            $m['supersededBy'] = $next;
            NetForecastBenchmarkSupport::save($path, $m);
            $m['parentManifestSha256'] = $previousHash;
            $m['noiseScriptSha256'] = hash_file('sha256', __FILE__);
            $m['olderClock'] = 'all_observations_and_normalization_minus365_v1';
            $m['state'] = 'seeding_corrected_older';
            $path = $next;
            NetForecastBenchmarkSupport::save($path, $m);
            NetForecastBenchmarkSupport::seed($db, $m, 'OLDER', older: true);
            $m['state'] = 'noise_ready';
        } elseif ('--noise' === $operation) {
            NetForecastBenchmarkSupport::seed($db, $m, 'FOREIGN', foreign: true);
            NetForecastBenchmarkSupport::seed($db, $m, 'OLDER', older: true);
            $m['state'] = 'noise_ready';
        } elseif ('--analyze' === $operation) {
            foreach (NetForecastBenchmarkSupport::TABLES as $table) {
                $db->executeStatement('ANALYZE '.$table);
            }
        } elseif ('--remove-noise' === $operation) {
            $before = hash_file('sha256', $path);
            $deleted = NetForecastBenchmarkSupport::cleanup($db, $m, 'noise');
            $m['noiseDeletedRows'] = $deleted;
            NetForecastBenchmarkSupport::save($path.'.noise-cleanup.json', ['manifestSha256Before' => $before, 'deletedRows' => $deleted, 'zeroProbesPassed' => true]);
            $m['state'] = 'ready';
        } elseif ('--cleanup' === $operation) {
            $m['deletedRows'] = NetForecastBenchmarkSupport::cleanup($db, $m, 'all');
            $m['state'] = 'cleaned';
        } else {
            throw new \RuntimeException('Operations: --seed [--stress|--stress-groups], --ready, --noise, --audit-noise, --repair-older, --remove-noise, --analyze, --cleanup.');
        }
    }
    NetForecastBenchmarkSupport::save($path, $m);
    echo json_encode(['operation' => $operation, 'state' => $m['state'], 'database' => $database], \JSON_THROW_ON_ERROR)."\n";
    $kernel->shutdown();
}
