<?php

declare(strict_types=1);

namespace App\Tests\Integration\SalesPlanning;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

final class SalesPlanConcurrencyTest extends KernelTestCase
{
    /** @var list<Process> */
    private array $children = [];
    /** @var list<string> */
    private array $companies = [];
    private Connection $observer;
    private string $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $database = $connection->fetchOne('SELECT current_database()');
        self::assertIsString($database);
        self::assertMatchesRegularExpression('/^app_test(?:[1-9][0-9]*)?$/D', $database);
        $this->observer = $connection;
        $this->database = $database;
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->children as $child) {
                if ($child->isRunning()) {
                    $child->stop(0.1);
                }
            }
            foreach ($this->companies as $companyId) {
                $this->command(['operation' => 'cleanup', 'companyId' => $companyId]);
                foreach (['audit_record', 'sales_plan_day_claim', 'sales_plan_line', 'sales_plan'] as $table) {
                    self::assertSame(0, $this->observer->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE company_id = :id', ['id' => $companyId]));
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testWorkerRejectsWrongDatabaseBeforeAnyFixtureWrites(): void
    {
        $company = Uuid::v7()->toRfc4122();
        $this->companies[] = $company;
        [$child, $input] = $this->start(['operation' => 'seed', 'companyId' => $company], 'app');
        $input->close();
        $child->wait();
        self::assertSame(1, $child->getExitCode());
        self::assertStringContainsString('RuntimeException', $child->getErrorOutput());
        self::assertSame(0, $this->observer->fetchOne('SELECT COUNT(*) FROM sales_plan WHERE company_id = :id', ['id' => $company]));
    }

    public function testDayEditAndApprovalUseRealHeaderLockInBothOrders(): void
    {
        foreach ([['edit', 'approve', 'saved'], ['approve', 'edit', 'approved']] as [$winner, $loser, $winnerOutcome]) {
            [$company, $first] = $this->fixture();
            $request = ['companyId' => $company, 'planId' => $first['id'], 'version' => $first['version']];
            [$won, $lost] = $this->race([...$request, 'operation' => $winner], [...$request, 'operation' => $loser]);
            self::assertSame($winnerOutcome, $won['outcome']);
            self::assertSame('version_conflict', $lost['outcome']);
            $header = $this->header($company, $first['id']);
            self::assertSame($first['version'] + 1, $header['version']);
            self::assertSame('edit' === $winner ? 'draft' : 'approved', $header['status']);
            self::assertSame('edit' === $winner ? 9 : 2, $this->observer->fetchOne("SELECT quantity FROM sales_plan_line WHERE company_id = :company AND sales_plan_id = :id AND plan_date = '2026-07-02'", ['company' => $company, 'id' => $first['id']]));
            if ('edit' === $winner) {
                $approved = $this->command([...$request, 'operation' => 'approve', 'version' => $header['version']]);
                self::assertSame('approved', $approved['outcome']);
            }
            self::assertSame(3, $this->claimCount('sales_plan_day_claim', $company, $first['id']));
            self::assertSame(1, $this->auditCount($company, $first['id'], 'sales_plan.approved'));
            self::assertSame('edit' === $winner ? 1 : 0, $this->auditCount($company, $first['id'], 'sales_plan.day_changed'));
        }
    }

    public function testOverlappingApprovalsWaitForUniqueClaimAndLoserRollsBack(): void
    {
        [$company, $first, $second] = $this->fixture();
        [$won, $lost] = $this->race(
            ['operation' => 'approve', 'companyId' => $company, 'planId' => $first['id'], 'version' => $first['version']],
            ['operation' => 'approve', 'companyId' => $company, 'planId' => $second['id'], 'version' => $second['version']],
        );
        self::assertSame('approved', $won['outcome']);
        self::assertSame('sales_plan_day_conflict', $lost['outcome']);
        self::assertSame('approved', $this->header($company, $first['id'])['status']);
        self::assertSame(['status' => 'draft', 'version' => $second['version']], $this->header($company, $second['id']));
        self::assertSame(3, $this->claimCount('sales_plan_day_claim', $company, $first['id']));
        self::assertSame(0, $this->claimCount('sales_plan_day_claim', $company, $second['id']));
        self::assertSame(1, $this->auditCount($company, $first['id'], 'sales_plan.approved'));
        self::assertSame(0, $this->auditCount($company, $second['id'], 'sales_plan.approved'));
        [$foreignCompany, $foreign] = $this->fixture();
        self::assertSame('sales_plan_not_found', $this->command(['operation' => 'approve', 'companyId' => $foreignCompany, 'planId' => $first['id'], 'version' => $first['version']])['outcome']);
        self::assertSame('approved', $this->command(['operation' => 'approve', 'companyId' => $foreignCompany, 'planId' => $foreign['id'], 'version' => $foreign['version']])['outcome']);
    }

    public function testCopyAndCancelSerializeOnSameSourceInBothOrders(): void
    {
        foreach ([['copy', 'cancel', 'created'], ['cancel', 'copy', 'changed']] as [$winner, $loser, $outcome]) {
            [$company, $first] = $this->fixture();
            $request = ['companyId' => $company, 'planId' => $first['id'], 'version' => $first['version']];
            self::assertSame('approved', $this->command([...$request, 'operation' => 'approve'])['outcome']);
            $version = $this->header($company, $first['id'])['version'];
            [$won, $lost] = $this->race([...$request, 'operation' => $winner, 'version' => $version], [...$request, 'operation' => $loser, 'version' => $version]);
            self::assertSame($outcome, $won['outcome']);
            self::assertSame('version_conflict', $lost['outcome']);
            if ('copy' === $winner) {
                self::assertSame('approved', $this->header($company, $first['id'])['status']);
                $fresh = $this->header($company, $first['id'])['version'];
                $again = $this->command([...$request, 'operation' => 'copy', 'version' => $fresh]);
                $draftId = $won['draftId'];
                self::assertIsString($draftId);
                self::assertSame($draftId, $again['draftId']);
                self::assertSame(1, $this->auditCount($company, $draftId, 'sales_plan.revision_created'));
                self::assertSame('changed', $this->command([...$request, 'operation' => 'cancel', 'version' => $this->header($company, $first['id'])['version']])['outcome']);
                self::assertSame('discarded', $this->header($company, $draftId)['status']);
            } else {
                self::assertSame(0, $this->observer->fetchOne('SELECT COUNT(*) FROM audit_record WHERE company_id = :company AND action = :action', ['company' => $company, 'action' => 'sales_plan.revision_created']));
            }
            self::assertSame('cancelled', $this->header($company, $first['id'])['status']);
            self::assertSame(0, $this->claimCount('sales_plan_day_claim', $company, $first['id']));
            self::assertSame(0, $this->observer->fetchOne("SELECT COUNT(*) FROM sales_plan WHERE company_id = :company AND lineage_id = :id AND status = 'draft'", ['company' => $company, 'id' => $first['id']]));
            self::assertSame(1, $this->auditCount($company, $first['id'], 'sales_plan.cancelled'));
            self::assertSame('sales_plan_not_approved', $this->command([...$request, 'operation' => 'copy', 'version' => $this->header($company, $first['id'])['version']])['outcome']);
        }
    }

    /** @return array{string, array{id: string, version: int}, array{id: string, version: int}} */
    private function fixture(): array
    {
        $company = Uuid::v7()->toRfc4122();
        $this->companies[] = $company;
        $seed = $this->command(['operation' => 'seed', 'companyId' => $company]);
        $plans = $seed['plans'] ?? null;
        self::assertIsArray($plans);

        return [$company, $this->planData($plans[0] ?? null), $this->planData($plans[1] ?? null)];
    }

    /** @return array{id: string, version: int} */
    private function planData(mixed $raw): array
    {
        self::assertIsArray($raw);
        $id = $raw['id'] ?? null;
        $version = $raw['version'] ?? null;
        self::assertIsString($id);
        self::assertIsInt($version);

        return ['id' => $id, 'version' => $version];
    }

    /** @param array<string, mixed> $request
     * @return array{Process, InputStream}
     */
    private function start(array $request, ?string $expectedDatabase = null): array
    {
        $input = new InputStream();
        $input->write(json_encode([...$request, 'database' => $expectedDatabase ?? $this->database], \JSON_THROW_ON_ERROR)."\n");
        $child = new Process([\PHP_BINARY, \dirname(__DIR__, 2).'/Support/SalesPlanning/concurrent-plan.php'], env: ['APP_ENV' => 'test', 'APP_DEBUG' => '1'], timeout: 20);
        $child->setInput($input);
        $this->children[] = $child;
        $child->start();

        return [$child, $input];
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function command(array $request): array
    {
        [$child, $input] = $this->start($request);
        $input->close();
        $child->wait();
        self::assertSame(0, $child->getExitCode(), $child->getErrorOutput());

        return $this->event($child, 'result');
    }

    /** @return array<string, mixed> */
    private function event(Process $child, string $event): array
    {
        $deadline = hrtime(true) + 8_000_000_000;
        do {
            $child->checkTimeout();
            foreach (explode("\n", $child->getOutput()) as $line) {
                if ('' === $line) {
                    continue;
                }
                $value = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
                if (\is_array($value) && ($value['event'] ?? null) === $event) {
                    /* @var array<string, mixed> $value */
                    return $value;
                }
            }
            if (!$child->isRunning()) {
                self::fail('Worker exited before barrier '.$event.': '.$child->getErrorOutput());
            }
            usleep(10_000);
        } while (hrtime(true) < $deadline);
        self::fail('Worker barrier deadline: '.$event);
    }

    /** @param array<string, mixed> $winner
     * @param array<string, mixed> $loser
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function race(array $winner, array $loser): array
    {
        [$first, $release] = $this->start([...$winner, 'hold' => true]);
        $held = $this->event($first, 'held');
        [$second, $input] = $this->start($loser);
        $input->close();
        $started = $this->event($second, 'started');
        $firstPid = $held['pid'];
        $secondPid = $started['pid'];
        self::assertIsInt($firstPid);
        self::assertIsInt($secondPid);
        self::assertNotSame($firstPid, $secondPid);
        self::assertNotSame($firstPid, $this->observer->fetchOne('SELECT pg_backend_pid()'));
        $deadline = hrtime(true) + 8_000_000_000;
        do {
            $this->observer->executeStatement('SELECT pg_stat_clear_snapshot()');
            $waiting = $this->observer->fetchOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = CAST(:loser AS int) AND CAST(:winner AS int) = ANY(pg_blocking_pids(pid))', ['loser' => $secondPid, 'winner' => $firstPid]);
            if ('Lock' === $waiting) {
                break;
            }
            self::assertTrue($second->isRunning(), 'Second worker exited without a real lock wait: '.$second->getErrorOutput());
            usleep(10_000);
        } while (hrtime(true) < $deadline);
        self::assertSame('Lock', $waiting, 'Concurrent backend must wait on the first backend.');
        $release->write("commit\n");
        $release->close();
        $first->wait();
        $second->wait();
        self::assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        self::assertSame(0, $second->getExitCode(), $second->getErrorOutput());
        $this->event($first, 'committed');

        return [$held, $this->event($second, 'result')];
    }

    /** @return array{status: string, version: int} */
    private function header(string $companyId, string $id): array
    {
        /** @var array{status: string, version: int}|false $row */
        $row = $this->observer->fetchAssociative('SELECT status, version FROM sales_plan WHERE company_id = :company AND id = :id', ['company' => $companyId, 'id' => $id]);
        self::assertIsArray($row);

        return $row;
    }

    private function claimCount(string $table, string $companyId, string $id): int
    {
        $count = $this->observer->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE company_id = :company AND sales_plan_id = :id', ['company' => $companyId, 'id' => $id]);
        self::assertIsInt($count);

        return $count;
    }

    private function auditCount(string $companyId, string $id, string $action): int
    {
        $count = $this->observer->fetchOne('SELECT COUNT(*) FROM audit_record WHERE company_id = :company AND subject_id = :id AND action = :action', ['company' => $companyId, 'id' => $id, 'action' => $action]);
        self::assertIsInt($count);

        return $count;
    }
}
