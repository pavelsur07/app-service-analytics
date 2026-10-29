<?php

declare(strict_types=1);

use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineMarketplaceAccountRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Application\Facade\IngestionFacade;
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
use App\Ingestion\Domain\DayLoadMark;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Infrastructure\Config\NetForecastCaptureEpochs;
use App\Ingestion\Infrastructure\Config\NetForecastPublication;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceDayLoadWriter;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceListingWriter;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplacePostingStatusWriter;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceReturnFactWriter;
use App\Ingestion\Infrastructure\Persistence\DoctrineSalesFactWriter;
use App\Ingestion\Infrastructure\Query\Buyout\BuyoutMaturityQuery;
use App\Ingestion\Infrastructure\Query\Listings\AccountCatalogSkuSearchQuery;
use App\Ingestion\Infrastructure\Query\Listings\AccountCatalogSkusPresentQuery;
use App\Ingestion\Infrastructure\Query\Listings\ListingSnapshotsQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\GrossTrainingAggregateQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAggregateQuery;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortCoverageQuery;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortMetricsQuery;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortSeriesQuery;
use App\Kernel;
use App\SalesPlanning\Application\ReadSalesPlanPerformanceAction;
use App\SalesPlanning\Application\SalesPlanPerformanceCalculator;
use App\SalesPlanning\Application\SalesPlanPerformanceRequest;
use App\SalesPlanning\Application\SalesPlanPerformanceSnapshot;
use App\SalesPlanning\Domain\SalesPlanMetric;
use App\SalesPlanning\Infrastructure\Persistence\DoctrineSalesPlanCalendarRepository;
use App\SalesPlanning\Infrastructure\Persistence\DoctrineSalesPlanRepository;
use App\SalesPlanning\Infrastructure\Query\SalesPlanBaselineQuery;
use App\SalesPlanning\Infrastructure\Query\SalesPlanGrouping;
use App\SalesPlanning\Infrastructure\Query\SalesPlanPerformanceQuery;
use App\SalesPlanning\Infrastructure\Query\SalesPlansQuery;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use App\Tests\Support\Builder\SalesPlanBuilder;
use App\Tests\Support\Builder\SalesPlanLineBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bridge\Doctrine\Middleware\Debug\Middleware;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 3).'/vendor/autoload.php';

/** @param array<string, mixed> $data */
function writeBenchmark(string $file, array $data): void
{
    $json = json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT);
    $previousMask = umask(0077);
    try {
        if (is_file($file.'.tmp') && !chmod($file.'.tmp', 0600)) {
            throw new RuntimeException('Cannot protect synthetic benchmark temporary state.');
        }
        if (false === file_put_contents($file.'.tmp', $json) || !rename($file.'.tmp', $file)) {
            throw new RuntimeException('Cannot persist synthetic benchmark state/artifact.');
        }
    } finally {
        umask($previousMask);
    }
    if (!chmod($file, 0600)) {
        throw new RuntimeException('Cannot persist synthetic benchmark state/artifact.');
    }
}

/** @param array<array-key, mixed> $data */
function benchmarkString(array $data, string $key): string
{
    if (!is_string($data[$key] ?? null)) {
        throw new RuntimeException('Invalid benchmark field: '.$key);
    }

    return $data[$key];
}

/** @return array{runId:string,userId:string,companyId:string,accountId:string,foreignCompanyId:string,foreignAccountId:string,plans:list<array{id:string,days:int,from:string,to:string,skuBase:int}>,fixture:array<string,int>,bootstrapPresent:bool} */
function benchmarkState(string $file, Connection $db, bool $allowAbsentBootstrap = false): array
{
    $raw = file_get_contents($file);
    $state = false === $raw ? null : json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
    if (!is_array($state)) {
        throw new RuntimeException('Missing synthetic benchmark state.');
    }
    foreach (['runId', 'userId', 'companyId', 'accountId', 'foreignCompanyId', 'foreignAccountId'] as $key) {
        if (!is_string($state[$key] ?? null) || !Uuid::isValid($state[$key]) || '7' !== $state[$key][14]) {
            throw new RuntimeException('Invalid synthetic benchmark identity.');
        }
    }
    $present = 0;
    foreach ([['companyId', 'accountId', 'owned'], ['foreignCompanyId', 'foreignAccountId', 'foreign']] as [$companyKey, $accountKey, $role]) {
        $name = $db->fetchOne('SELECT name FROM company WHERE id = ?', [$state[$companyKey]]);
        if (false !== $name) {
            if ('Synthetic planning benchmark '.$state['runId'] !== $name) {
                throw new RuntimeException('Refusing a nonsynthetic benchmark company/account.');
            }
            ++$present;
        }
        $account = $db->fetchAssociative('SELECT company_id, external_shop_id FROM marketplace_account WHERE company_id = ? AND id = ?', [$state[$companyKey], $state[$accountKey]]);
        if (false !== $account) {
            if ($account['company_id'] !== $state[$companyKey] || $account['external_shop_id'] !== 'benchmark-'.$role.'-'.$state['runId']) {
                throw new RuntimeException('Refusing a nonsynthetic benchmark company/account.');
            }
            ++$present;
        }
        if (false !== $db->fetchOne('SELECT id FROM marketplace_account WHERE company_id = ? AND id <> ? LIMIT 1', [$state[$companyKey], $state[$accountKey]])) {
            throw new RuntimeException('Refusing unexpected accounts in a synthetic benchmark company.');
        }
    }
    $email = $db->fetchOne('SELECT email FROM "user" WHERE id = ?', [$state['userId']]);
    if (false !== $email) {
        if ('benchmark-planning+'.$state['runId'].'@example.com' !== $email) {
            throw new RuntimeException('Refusing a nonsynthetic benchmark user.');
        }
        ++$present;
    }
    if (false !== $db->fetchOne('SELECT company_id FROM company_member WHERE user_id = ? AND company_id <> ? LIMIT 1', [$state['userId'], $state['companyId']])) {
        throw new RuntimeException('Refusing unexpected memberships of a synthetic benchmark user.');
    }
    // A killed/rolled-back bootstrap has no committed identities. Never issue
    // child-table DELETEs using only an absent parent's UUID from the state.
    if (5 !== $present && !(0 === $present && $allowAbsentBootstrap)) {
        throw new RuntimeException('Incomplete synthetic benchmark bootstrap.');
    }

    $plans = [];
    if (!is_array($state['plans'] ?? null)) {
        throw new RuntimeException('Invalid benchmark plans.');
    }
    foreach ($state['plans'] as $plan) {
        if (!is_array($plan) || !is_int($plan['days'] ?? null) || !in_array($plan['days'], [31, 184], true) || !is_int($plan['skuBase'] ?? null)) {
            throw new RuntimeException('Invalid benchmark plan.');
        }
        $plans[] = ['id' => benchmarkString($plan, 'id'), 'days' => $plan['days'], 'from' => benchmarkString($plan, 'from'), 'to' => benchmarkString($plan, 'to'), 'skuBase' => $plan['skuBase']];
    }
    $fixture = [];
    foreach (is_array($state['fixture'] ?? null) ? $state['fixture'] : [] as $key => $value) {
        if (!is_string($key) || !is_int($value)) {
            throw new RuntimeException('Invalid benchmark fixture volume.');
        }
        $fixture[$key] = $value;
    }

    return ['runId' => benchmarkString($state, 'runId'), 'userId' => benchmarkString($state, 'userId'), 'companyId' => benchmarkString($state, 'companyId'), 'accountId' => benchmarkString($state, 'accountId'), 'foreignCompanyId' => benchmarkString($state, 'foreignCompanyId'), 'foreignAccountId' => benchmarkString($state, 'foreignAccountId'), 'plans' => $plans, 'fixture' => $fixture, 'bootstrapPresent' => 5 === $present];
}

/** Batch fact writers; loops construct objects and never issue SQL per row. */
function benchmarkFacts(Connection $db, Uuid $company, Uuid $account, DateTimeImmutable $start, int $days, int $skuBase, int $skuCount, string $prefix, bool $mix, bool $sameDay = false): void
{
    $facts = $first = $last = $returns = [];
    for ($dayIndex = 0; $dayIndex < $days; ++$dayIndex) {
        $day = $sameDay ? $start : $start->modify('+'.$dayIndex.' days');
        for ($skuIndex = 0; $skuIndex < $skuCount; ++$skuIndex) {
            $sku = (string) ($skuBase + $skuIndex);
            $posting = $prefix.'-'.$dayIndex.'-'.$sku;
            $kind = $mix ? ($dayIndex + $skuIndex) % 10 : 0;
            $status = match ($kind) {
                1 => 'awaiting_packaging', 2 => 'delivering', 3 => 'cancelled', default => 'delivered',
            };
            $facts[] = SalesFactBuilder::aSalesFact()->withCompanyId($company)->withMarketplaceAccountId($account)
                ->withBusinessDate($day)->withMarketplaceSku($sku)->withSourceRowId($posting.'|'.$sku)
                ->withPostingNumber($posting)->withOrderNumber($posting)->withStatus($status)->withQuantity(3)
                ->withAmount(Money::ofMinor(12345, 'RUB'))->withCommissionAmount(Money::ofMinor(0, 'RUB'))
                ->withOrderedAt($day->setTime(8, 0))->withRawDocumentId(Uuid::v7())->build();
            $builder = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($company)->withMarketplaceAccountId($account)
                ->withPostingNumber($posting)->withOrderNumber($posting);
            $first[] = $builder->withStatus(in_array($kind, [1, 3], true) ? 'awaiting_packaging' : 'delivering')
                ->withObservedAt($day->setTime(9, 0))->withRawDocumentId(Uuid::v7())->build();
            $last[] = $builder->withStatus($status)->withObservedAt($day->modify('+1 day')->setTime(10, 0))->withRawDocumentId(Uuid::v7())->build();
            if (3 === $kind || 4 === $kind) {
                $returns[] = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()->withCompanyId($company)->withMarketplaceAccountId($account)
                    ->withSourceRowId('return-'.$posting)->withPostingNumber($posting)->withOrderNumber($posting)->withMarketplaceSku($sku)
                    ->withReturnType(3 === $kind ? 'Cancellation' : 'ClientReturn')->withReturnReasonName('Synthetic benchmark return')
                    ->withQuantity(1)->withVisualStatus(1, 'Completed', $day->modify('+2 days')->setTime(10, 0))->withRawDocumentId(Uuid::v7())->build();
            }
        }
    }
    (new DoctrineSalesFactWriter($db))->upsertAll($facts);
    $statuses = new DoctrineMarketplacePostingStatusWriter($db);
    $statuses->recordChanged($company->toRfc4122(), $first);
    $statuses->recordChanged($company->toRfc4122(), $last);
    (new DoctrineMarketplaceReturnFactWriter($db))->upsertAll($returns);
}

/** @return list<array{sql:string,params:list<mixed>,types:list<ParameterType>,seconds:float}> */
function benchmarkQueries(DebugDataHolder $debug): array
{
    $data = $debug->getData()['benchmark'] ?? [];
    if (!is_array($data)) {
        throw new RuntimeException('Invalid captured query collection.');
    }
    $result = [];
    foreach ($data as $query) {
        if (!is_array($query) || !is_string($query['sql'] ?? null) || !is_array($query['params'] ?? null) || !is_array($query['types'] ?? null) || !is_float($query['executionMS'] ?? null)) {
            throw new RuntimeException('Invalid captured query.');
        }
        $types = [];
        foreach ($query['types'] as $type) {
            if (!$type instanceof ParameterType) {
                throw new RuntimeException('Invalid captured parameter type.');
            }
            $types[] = $type;
        }
        $result[] = ['sql' => $query['sql'], 'params' => array_values($query['params']), 'types' => $types, 'seconds' => $query['executionMS']];
    }

    return $result;
}

try {
    if ('dev' !== getenv('APP_ENV') || '1' !== getenv('CONWIX_BENCHMARK_PLANNING')) {
        throw new RuntimeException('Only explicitly enabled local dev planning benchmark is allowed.');
    }
    $operation = $argv[1] ?? '';
    if (!in_array($operation, ['seed', 'explain', 'cleanup'], true)) {
        throw new RuntimeException('Unknown synthetic benchmark operation.');
    }
    $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'dev';
    (new Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
    $kernel = new Kernel('dev', true);
    $kernel->boot();
    $registry = $kernel->getContainer()->get('doctrine');
    if (!$registry instanceof ManagerRegistry || !($em = $registry->getManager()) instanceof EntityManagerInterface) {
        throw new RuntimeException('Missing local ORM manager.');
    }
    $db = $em->getConnection();
    $parameters = $db->getParams();
    if ('postgres' !== ($parameters['host'] ?? null) || 'app' !== ($parameters['user'] ?? null)
        || 'app' !== $db->fetchOne('SELECT current_database()') || 'dev' !== $kernel->getEnvironment()) {
        throw new RuntimeException('Only local app database is allowed.');
    }
    $directory = dirname(__DIR__, 3).'/var/benchmark-sales-planning';
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
        throw new RuntimeException('Cannot create local benchmark artifact directory.');
    }
    $lock = fopen($directory.'/operation.lock', 'c');
    if (false === $lock || !flock($lock, \LOCK_EX | \LOCK_NB)) {
        throw new RuntimeException('Another synthetic benchmark operation is active.');
    }
    $stateFile = $directory.'/state.json';
    if ('seed' === $operation) {
        if (is_file($stateFile)) {
            throw new RuntimeException('Clean up the preceding synthetic benchmark first.');
        }
        $runId = Uuid::v7()->toRfc4122();
        $password = bin2hex(random_bytes(24));
        $companies = new DoctrineCompanyRepository($em);
        $users = new DoctrineUserRepository($em);
        $user = UserBuilder::aUser()->withEmail('benchmark-planning+'.$runId.'@example.com')->withPasswordHash(password_hash($password, \PASSWORD_BCRYPT))->build();
        $company = CompanyBuilder::aCompany()->withName('Synthetic planning benchmark '.$runId)->build();
        $foreign = CompanyBuilder::aCompany()->withName('Synthetic planning benchmark '.$runId)->build();
        $account = MarketplaceAccountBuilder::aMarketplaceAccount()->withCompany($company)->withExternalShopId('benchmark-owned-'.$runId)->build();
        $foreignAccount = MarketplaceAccountBuilder::aMarketplaceAccount()->withCompany($foreign)->withExternalShopId('benchmark-foreign-'.$runId)->build();
        $member = CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->build();
        $state = ['runId' => $runId, 'userId' => $user->id()->toRfc4122(), 'companyId' => $company->id()->toRfc4122(), 'accountId' => $account->id()->toRfc4122(), 'foreignCompanyId' => $foreign->id()->toRfc4122(), 'foreignAccountId' => $foreignAccount->id()->toRfc4122(), 'email' => $user->email(), 'password' => $password, 'plans' => []];
        // Write-ahead recovery IDs become durable before the first ORM write.
        writeBenchmark($stateFile, $state);
        $db->transactional(static function () use ($em, $companies, $users, $user, $company, $foreign, $account, $foreignAccount, $member): void {
            $users->add($user);
            $companies->add($company);
            $companies->add($foreign);
            $accounts = new DoctrineMarketplaceAccountRepository($em);
            $accounts->add($account);
            $accounts->add($foreignAccount);
            (new DoctrineCompanyMemberRepository($em))->add($member);
        });
        $company = Uuid::fromString($state['companyId']);
        $account = Uuid::fromString($state['accountId']);
        $to = new DateTimeImmutable('yesterday', new DateTimeZone('Europe/Moscow'));
        $repository = new DoctrineSalesPlanRepository($em, new DoctrineSalesPlanCalendarRepository($em));
        $listings = [];
        foreach ([[31, 98000000], [184, 98000100]] as [$days, $base]) {
            $from = $to->modify('-'.($days - 1).' days');
            $plan = SalesPlanBuilder::aSalesPlan()->withCompanyId($company)->withMarketplaceAccountId($account)->withCreatedByUserId(Uuid::fromString($state['userId']))
                ->withName('Synthetic benchmark '.$days)->withPeriod($from, $to)->withMetric(SalesPlanMetric::RetainedSales)->build();
            $lines = [];
            for ($i = 0; $i < 50; ++$i) {
                $sku = (string) ($base + $i);
                array_push($lines, ...SalesPlanLineBuilder::linesOf($plan)->withMarketplaceSku($sku)->withTotalQuantity($days * 5)->withPrice(Money::ofMinor(15000, 'RUB'))->build());
                $listings[] = MarketplaceListingBuilder::aMarketplaceListing()->withCompanyId($company)->withMarketplaceAccountId($account)->withMarketplaceSku($sku)->withName('Synthetic benchmark SKU '.$sku)->withOfferId('BENCH-'.$sku)->withPhotoUrl(null)->build();
            }
            $repository->add($plan, $lines);
            $state['plans'][] = ['id' => $plan->id()->toRfc4122(), 'days' => $days, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'skuBase' => $base];
            writeBenchmark($stateFile, $state);
            benchmarkFacts($db, $company, $account, $from, $days, $base, 50, 'season'.$days, true);
            // Thirty observed postings/SKU on one mature day keep the account
            // in-flight share below 3%; spreading them thinly would silently
            // exclude every mixed season day from training_days.
            benchmarkFacts($db, $company, $account, $to->modify('-7 days'), 30, $base, 50, 'training'.$days, false, true);
        }
        (new DoctrineMarketplaceListingWriter($db))->replaceForAccount($state['companyId'], $account, $listings);
        (new DoctrineMarketplaceDayLoadWriter($db))->mark($state['companyId'], $state['accountId'], [DayLoadMark::completed(MarketplaceReportType::OzonPostingFboList, $to->modify('-183 days'), $to)], new DateTimeImmutable());
        benchmarkFacts($db, Uuid::fromString($state['foreignCompanyId']), Uuid::fromString($state['foreignAccountId']), $to->modify('-199 days'), 200, 98000000, 100, 'foreign', false);
        // Bulk fixtures invalidate the tiny pre-existing sandbox estimates.
        // Do not race autovacuum's eventual ANALYZE in a repeatable benchmark.
        $db->executeStatement('ANALYZE sales_fact, marketplace_posting_status, marketplace_return_fact, sales_plan, sales_plan_line, marketplace_day_load, marketplace_listing');
        $state['fixture'] = ['ownSeasonFacts' => 10750, 'trainingFacts' => 3000, 'foreignFacts' => 20000, 'planLines' => 10750];
        writeBenchmark($stateFile, $state);
        fwrite(\STDOUT, "Synthetic benchmark fixture ready.\n");
    } elseif ('cleanup' === $operation) {
        if (is_file($stateFile)) {
            $state = benchmarkState($stateFile, $db, true);
            if ($state['bootstrapPresent']) {
                $db->transactional(static function () use ($db, $state): void {
                    foreach ([$state['companyId'], $state['foreignCompanyId']] as $companyId) {
                        foreach (['sales_plan_calendar_entry', 'sales_plan_day_claim', 'sales_plan_line', 'sales_plan', 'marketplace_listing', 'sales_fact_revision', 'marketplace_return_fact_revision', 'marketplace_day_load_receipt', 'sales_fact', 'marketplace_return_fact', 'marketplace_posting_status', 'marketplace_day_load', 'audit_record', 'company_member', 'marketplace_account'] as $table) {
                            $db->executeStatement('DELETE FROM '.$table.' WHERE company_id = ?', [$companyId]);
                        }
                        $db->executeStatement('DELETE FROM company WHERE id = ?', [$companyId]);
                    }
                    $db->executeStatement('DELETE FROM "user" WHERE id = ? AND email = ?', [$state['userId'], 'benchmark-planning+'.$state['runId'].'@example.com']);
                });
            }
            unlink($stateFile);
        }
    } else {
        $state = benchmarkState($stateFile, $db);
        $db->executeStatement("SET statement_timeout = '30s'");
        $results = [];
        foreach ($state['plans'] as $plan) {
            foreach (31 === $plan['days'] ? ['day'] : ['day', 'week', 'month', 'period'] as $group) {
                $from = 'day' === $group ? (new DateTimeImmutable($plan['to']))->modify('-30 days')->format('Y-m-d') : $plan['from'];
                foreach ([1, 50] as $limit) {
                    $debug = new DebugDataHolder();
                    $profiled = DriverManager::getConnection($db->getParams(), (new Configuration())->setMiddlewares([new Middleware($debug, null, 'benchmark')]));
                    $profiled->executeStatement("SET statement_timeout = '30s'");
                    $debug->reset();
                    $curves = new BuildReturnCurveAction();
                    $pre = new BuildPreHandoverGrossRateAction();
                    $post = new BuildPostHandoverGrossRateAction();
                    $net = new ReadNetForecastAction($profiled,
                        new ReadNetTrainingAction($profiled,
                            new GrossTrainingAggregateQuery($profiled),
                            new ReturnTrainingAggregateQuery($profiled),
                            new NetForecastCaptureEpochs()),
                        new NetTargetQuery($profiled),
                        new AggregateNetForecastAction(
                            new AssessDeliveredRiskAction($curves),
                            new AssessOpenRetentionAction($pre, $post, $curves,
                                new ApplyOpenRetentionAction($pre, $post, $curves))));
                    $facade = new IngestionFacade(new ListingSnapshotsQuery($profiled), new AccountCatalogSkuSearchQuery($profiled), new AccountCatalogSkusPresentQuery($profiled), new SalesCohortSeriesQuery($profiled, new SalesCohortMetricsQuery($profiled), new SalesCohortCoverageQuery($profiled)), new BuyoutMaturityQuery($profiled), new NativeClock(),
                        new NetForecastPublication(), $net,
                        new NetTrainingSnapshot($profiled));
                    $action = new ReadSalesPlanPerformanceAction(new SalesPlanPerformanceSnapshot($profiled), new SalesPlansQuery($profiled), new SalesPlanBaselineQuery($profiled), new SalesPlanPerformanceQuery($profiled), $facade, new SalesPlanPerformanceCalculator());
                    $started = hrtime(true);
                    $view = $action($state['companyId'], $plan['id'], new SalesPlanPerformanceRequest(new DateTimeImmutable($from), new DateTimeImmutable($plan['to']), SalesPlanGrouping::from($group), limit: $limit));
                    $wallMs = (hrtime(true) - $started) / 1_000_000;
                    $queries = benchmarkQueries($debug);
                    $profiled->close();
                    $selects = [];
                    foreach ($queries as $query) {
                        if (!str_starts_with(ltrim($query['sql']), 'SELECT')) {
                            continue;
                        }
                        $explain = $db->executeQuery('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query['sql'], $query['params'], $query['types'])->fetchOne();
                        if (!is_string($explain)) {
                            throw new RuntimeException('Expected EXPLAIN JSON.');
                        }
                        $selects[] = ['sql' => $query['sql'], 'executionMs' => $query['seconds'] * 1000, 'explain' => json_decode($explain, true, flags: \JSON_THROW_ON_ERROR)];
                    }
                    $key = $plan['days'].'-'.$group.'-'.$limit;
                    writeBenchmark($directory.'/sql-'.$key.'.json', ['scenario' => $key, 'actionWallMs' => $wallMs, 'rows' => count($view->skus), 'queries' => $selects]);
                    $results[$key] = ['actionWallMs' => $wallMs, 'selectCount' => count($selects), 'sqlExecutionMs' => array_sum(array_column($selects, 'executionMs'))];
                    if (5 !== count($selects) || count($view->skus) !== $limit) {
                        throw new RuntimeException('Unexpected query count/page size in '.$key);
                    }
                }
            }
        }
        writeBenchmark($directory.'/sql-summary.json', ['at' => gmdate(\DATE_ATOM), 'fixture' => $state['fixture'], 'scenarios' => $results]);
        fwrite(\STDOUT, "SQL measurement artifacts saved.\n");
    }
} catch (Throwable $error) {
    fwrite(\STDERR, $error->getMessage()."\n");
    exit(1);
}
