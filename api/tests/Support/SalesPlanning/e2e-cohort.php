<?php

declare(strict_types=1);

use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\DayLoadMark;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceDayLoadWriter;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceListingWriter;
use App\Kernel;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 3).'/vendor/autoload.php';

/** @param array<string, mixed> $data */
function writePlanningFixture(string $path, array $data): void
{
    if (false === file_put_contents($path.'.tmp', json_encode($data, \JSON_THROW_ON_ERROR)) || !rename($path.'.tmp', $path)) {
        throw new RuntimeException('Cannot write synthetic planning fixture.');
    }
}

/** @param array<string, string> $arguments */
function importPlanningFixture(Application $application, string $command, array $arguments): void
{
    $output = new BufferedOutput();
    if (0 !== $application->find($command)->run(new ArrayInput($arguments), $output)) {
        throw new RuntimeException('Synthetic planning importer failed: '.$command);
    }
}

/** @return array{companyId: string, accountId: string, from: string, to: string, sku: string, runId: string} */
function planningFixtureState(string $stateFile, Connection $db, bool $allowUncommittedBootstrap = false): array
{
    $raw = file_get_contents($stateFile);
    $state = false === $raw ? null : json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
    if (!is_array($state)) {
        throw new RuntimeException('Missing synthetic planning state.');
    }
    foreach (['companyId', 'accountId', 'from', 'to', 'sku', 'runId'] as $key) {
        if (!is_string($state[$key] ?? null)) {
            throw new RuntimeException('Invalid synthetic planning state.');
        }
    }
    if (!Uuid::isValid($state['companyId']) || !Uuid::isValid($state['accountId']) || !Uuid::isValid($state['runId']) || '99000001' !== $state['sku']) {
        throw new RuntimeException('Invalid synthetic planning scope.');
    }
    $companyName = $db->fetchOne('SELECT name FROM company WHERE id = ?', [$state['companyId']]);
    $account = $db->fetchOne('SELECT external_shop_id FROM marketplace_account WHERE company_id = ? AND id = ?', [$state['companyId'], $state['accountId']]);
    if ((false !== $companyName && 'E2E Sales Planning' !== $companyName)
        || (false !== $account && $account !== 'e2e-plan-'.$state['companyId'])
        || false !== $db->fetchOne('SELECT id FROM marketplace_account WHERE company_id = ? AND id <> ? LIMIT 1', [$state['companyId'], $state['accountId']])
        || (!$allowUncommittedBootstrap && (false === $companyName || false === $account))) {
        throw new RuntimeException('Refusing a nonsynthetic planning company/account.');
    }

    return ['companyId' => $state['companyId'], 'accountId' => $state['accountId'], 'from' => $state['from'], 'to' => $state['to'], 'sku' => $state['sku'], 'runId' => $state['runId']];
}

try {
    if ('dev' !== getenv('APP_ENV') || '1' !== getenv('CONWIX_E2E_PLANNING')) {
        throw new RuntimeException('Only explicitly enabled local dev E2E is allowed.');
    }
    $operation = $argv[1] ?? '';
    if (!in_array($operation, ['seed', 'wait', 'cleanup'], true)) {
        throw new RuntimeException('Unknown synthetic planning operation.');
    }
    $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'dev';
    (new Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
    $kernel = new Kernel('dev', true);
    $kernel->boot();
    $registry = $kernel->getContainer()->get('doctrine');
    if (!$registry instanceof ManagerRegistry) {
        throw new RuntimeException('Missing local Doctrine registry.');
    }
    $em = $registry->getManager();
    if (!$em instanceof EntityManagerInterface) {
        throw new RuntimeException('Missing local ORM manager.');
    }
    $db = $em->getConnection();
    if ('app' !== $db->fetchOne('SELECT current_database()') || 'dev' !== $kernel->getEnvironment()) {
        throw new RuntimeException('Only local app database is allowed.');
    }
    $directory = dirname(__DIR__, 3).'/var';
    $stateFile = $directory.'/e2e-sales-planning-state.json';
    $requestFile = $directory.'/e2e-sales-planning-request.json';
    $doneFile = $directory.'/e2e-sales-planning-done.json';
    $stopFile = $directory.'/e2e-sales-planning-stop';
    $application = new Application($kernel);
    $application->setAutoExit(false);
    if ('seed' === $operation) {
        if (is_file($stateFile)) {
            throw new RuntimeException('Clean up the preceding synthetic planning state first.');
        }
        $email = $argv[2] ?? '';
        if (1 !== preg_match('/^e2e-owner\+[0-9a-f-]{36}@example\.com$/D', $email)) {
            throw new RuntimeException('Only the E2E sandbox owner is allowed.');
        }
        $users = new DoctrineUserRepository($em);
        $user = $users->findByEmail($email);
        if (null === $user || false === $db->fetchOne('SELECT m.user_id FROM company_member m JOIN company c ON c.id = m.company_id WHERE m.user_id = ? AND c.name = ? LIMIT 1', [$user->id()->toRfc4122(), 'E2E Sandbox LLC'])) {
            throw new RuntimeException('E2E owner membership was not found.');
        }
        $company = CompanyBuilder::aCompany()->withName('E2E Sales Planning')->build();
        $account = MarketplaceAccountBuilder::aMarketplaceAccount()->withCompany($company)->withExternalShopId('e2e-plan-'.$company->id()->toRfc4122())->withName('E2E planning fixture')->build();
        $member = CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->build();
        $from = new DateTimeImmutable('today -3 days', new DateTimeZone('Europe/Moscow'));
        $to = $from->modify('+2 days');
        $state = ['companyId' => $company->id()->toRfc4122(), 'accountId' => $account->id()->toRfc4122(), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'sku' => '99000001', 'runId' => Uuid::v7()->toRfc4122()];
        writePlanningFixture($stateFile, $state);
        // Write-ahead scope survives a killed seed; a killed transaction rolls
        // back, and cleanup also accepts that recorded but absent bootstrap.
        $em->wrapInTransaction(static function () use ($em, $company, $account, $member): void {
            $em->persist($company);
            $em->persist($account);
            $em->persist($member);
        });
        foreach ([$requestFile, $doneFile, $stopFile] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $listing = MarketplaceListingBuilder::aMarketplaceListing()->withCompanyId($company->id())->withMarketplaceAccountId($account->id())->withMarketplaceSku($state['sku'])->withName('Тестовый товар планирования')->withOfferId('E2E-PLAN')->withPhotoUrl(null)->build();
        (new DoctrineMarketplaceListingWriter($db))->replaceForAccount($state['companyId'], $account->id(), [$listing]);
        $fixture = $directory.'/e2e-sales-planning-postings.json';
        foreach ([['delivering', 'posting_on_way_to_city', '10:00:00'], ['delivered', 'posting_received', '11:00:00']] as [$status, $substatus, $clock]) {
            writePlanningFixture($fixture, ['result' => [[
                'order_id' => 99000001, 'order_number' => 'E2E-PLAN-ORDER', 'posting_number' => 'E2E-PLAN-ORDER-1',
                'status' => $status, 'substatus' => $substatus, 'cancel_reason_id' => 0,
                'in_process_at' => $state['from'].'T08:05:00Z',
                'products' => [['sku' => 99000001, 'quantity' => 3, 'offer_id' => 'E2E-PLAN', 'name' => 'Synthetic planning product']],
                'financial_data' => ['products' => [['product_id' => 99000001, 'price' => 100, 'commission_amount' => 0, 'currency_code' => 'RUB']]],
            ]]]);
            importPlanningFixture($application, 'app:ingestion:import-ozon-fixture', ['companyId' => $state['companyId'], 'marketplaceAccountId' => $state['accountId'], 'businessDate' => $state['from'], 'fixturePath' => $fixture, '--observed-at' => $state['from'].'T'.$clock.'Z']);
        }
        (new DoctrineMarketplaceDayLoadWriter($db))->mark($state['companyId'], $state['accountId'], [DayLoadMark::completed(MarketplaceReportType::OzonPostingFboList, $from, $to)], new DateTimeImmutable());
        fwrite(\STDOUT, "Synthetic planning fixture ready.\n");
    } elseif ('wait' === $operation) {
        $state = planningFixtureState($stateFile, $db);
        $deadline = microtime(true) + 900;
        while (microtime(true) < $deadline && !is_file($stopFile)) {
            if (!is_file($requestFile)) {
                usleep(100_000);
                continue;
            }
            $raw = file_get_contents($requestFile);
            $request = false === $raw ? null : json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
            if (!is_array($request) || $request !== ['phase' => 'return_one', 'runId' => $state['runId']]) {
                throw new RuntimeException('Invalid synthetic planning request.');
            }
            planningFixtureState($stateFile, $db);
            $fixture = $directory.'/e2e-sales-planning-return.json';
            writePlanningFixture($fixture, ['returns' => [[
                'id' => 99000001, 'return_reason_name' => 'Synthetic client return', 'type' => 'ClientReturn', 'schema' => 'Fbo',
                'order_number' => 'E2E-PLAN-ORDER', 'posting_number' => 'E2E-PLAN-ORDER-1', 'source_id' => 99000001,
                'product' => ['sku' => 99000001, 'offer_id' => 'E2E-PLAN', 'name' => 'Synthetic planning product', 'quantity' => 1],
                'visual' => ['status' => ['id' => 1, 'display_name' => 'Synthetic completed', 'sys_name' => 'Completed'], 'change_moment' => $state['to'].'T10:00:00Z'],
            ]], 'has_next' => false]);
            importPlanningFixture($application, 'app:ingestion:import-ozon-returns-fixture', ['companyId' => $state['companyId'], 'marketplaceAccountId' => $state['accountId'], 'businessDate' => $state['to'], 'fixturePath' => $fixture]);
            writePlanningFixture($doneFile, ['phase' => 'return_one', 'runId' => $state['runId']]);
            exit(0);
        }
        if (!is_file($stopFile)) {
            throw new RuntimeException('Synthetic planning feed timed out.');
        }
    } elseif (is_file($stateFile)) {
        $state = planningFixtureState($stateFile, $db, true);
        $db->transactional(static function () use ($db, $state): void {
            foreach (['sales_plan_calendar_entry', 'sales_plan_day_claim', 'sales_plan_line', 'sales_plan', 'marketplace_listing', 'sales_fact_revision', 'marketplace_return_fact_revision', 'marketplace_day_load_receipt', 'sales_fact', 'marketplace_return_fact', 'marketplace_posting_status', 'marketplace_day_load', 'marketplace_raw_document', 'audit_record', 'company_member', 'marketplace_account'] as $table) {
                $db->executeStatement('DELETE FROM '.$table.' WHERE company_id = ?', [$state['companyId']]);
            }
            $db->executeStatement('DELETE FROM company WHERE id = ?', [$state['companyId']]);
        });
        foreach ([$stateFile, $requestFile, $doneFile, $stopFile, $directory.'/e2e-sales-planning-postings.json', $directory.'/e2e-sales-planning-return.json'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
} catch (Throwable $error) {
    fwrite(\STDERR, $error->getMessage()."\n");
    exit(1);
}
