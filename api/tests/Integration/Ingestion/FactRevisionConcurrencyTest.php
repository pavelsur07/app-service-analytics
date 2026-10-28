<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

final class FactRevisionConcurrencyTest extends KernelTestCase
{
    /** @var list<Process> */
    private array $children = [];
    /** @var list<array{database: string, companyId: string, accountId: string, rawId: string, kind: string, quantity: int}> */
    private array $fixtures = [];
    private Connection $db;
    private string $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $database = $db->fetchOne('SELECT current_database()');
        self::assertIsString($database);
        self::assertMatchesRegularExpression('/^app_test(?:[1-9][0-9]*)?$/D', $database);
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
            foreach ($this->fixtures as $fixture) {
                [$process, $input] = $this->start([...$fixture, 'operation' => 'cleanup']);
                $input->close();
                $this->event($process, 'cleaned');
                $process->wait();
                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testConcurrentInsertsAndIdenticalRetryAreSerialized(): void
    {
        foreach (['sales', 'returns'] as $kind) {
            $fixture = $this->fixture($kind);
            $this->race($fixture, 1, 2);
            self::assertSame([1, 2], $this->column($fixture, 'fact_revision'));
            self::assertSame([1, 2], $this->column($fixture, 'quantity'));
            $same = $this->fixture($kind);
            $this->race($same, 1, 1);
            self::assertSame([1], $this->column($same, 'fact_revision'));
        }
    }

    public function testWorkerRefusesANonMatchingDatabaseBeforeAnyFixtureWrites(): void
    {
        $fixture = $this->fixture('sales');
        [$process, $input] = $this->start([...$fixture, 'database' => 'app']);
        $input->close();
        $process->wait();
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('nonmatching test worker database', $process->getErrorOutput());
        self::assertFalse($this->db->fetchOne('SELECT 1 FROM sales_fact WHERE company_id = ? LIMIT 1', [$fixture['companyId']]));
    }

    public function testSameRawSameSecondConcurrentAbaPreservesEveryAcceptedState(): void
    {
        foreach (['sales', 'returns'] as $kind) {
            $fixture = $this->fixture($kind);
            $this->write($fixture);
            $this->race($fixture, 2, 1);
            self::assertSame([1, 2, 3], $this->column($fixture, 'fact_revision'));
            self::assertSame([1, 2, 1], $this->column($fixture, 'quantity'));
            self::assertSame(array_fill(0, 3, '2026-10-28 12:00:00'), $this->column($fixture, 'captured_at'));
        }
    }

    public function testRolledBackWinnerDoesNotLeakStateOrConsumeRevision(): void
    {
        foreach (['sales', 'returns'] as $kind) {
            $fixture = $this->fixture($kind);
            $this->write($fixture);
            $this->race($fixture, 2, 3, true);
            self::assertSame([1, 2], $this->column($fixture, 'fact_revision'));
            self::assertSame([1, 3], $this->column($fixture, 'quantity'));
        }
    }

    /** @return array{database: string, companyId: string, accountId: string, rawId: string, kind: string, quantity: int} */
    private function fixture(string $kind): array
    {
        $fixture = ['database' => $this->database, 'companyId' => Uuid::v7()->toRfc4122(), 'accountId' => Uuid::v7()->toRfc4122(), 'rawId' => Uuid::v7()->toRfc4122(), 'kind' => $kind, 'quantity' => 1];
        $this->fixtures[] = $fixture;

        return $fixture;
    }

    /** @param array<string, int|string> $fixture */
    private function race(array $fixture, int $firstQuantity, int $secondQuantity, bool $rollbackFirst = false): void
    {
        [$first, $firstInput] = $this->start([...$fixture, 'quantity' => $firstQuantity]);
        $firstEvent = $this->event($first, 'ready');
        $firstInput->write("go\n");
        $this->event($first, 'written');
        [$second, $secondInput] = $this->start([...$fixture, 'quantity' => $secondQuantity]);
        $secondEvent = $this->event($second, 'ready');
        $secondInput->write("go\n");
        $deadline = microtime(true) + 5;
        do {
            // Pump InputStream into the subprocess before observing its backend.
            self::assertTrue($second->isRunning(), $second->getErrorOutput());
            $this->db->executeStatement('SELECT pg_stat_clear_snapshot()');
            $blocked = $this->db->fetchOne('SELECT CAST(:first AS int) = ANY(pg_blocking_pids(CAST(:second AS int)))', ['first' => $firstEvent['pid'], 'second' => $secondEvent['pid']]);
            if (true === $blocked) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $activity = $this->db->fetchAllAssociative('SELECT pid, state, wait_event_type, wait_event, pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE pid IN (:first, :second)', ['first' => $firstEvent['pid'], 'second' => $secondEvent['pid']]);
        self::assertTrue($blocked, 'The competing UPSERT must wait for the actual fact row lock. '.json_encode($activity, \JSON_THROW_ON_ERROR).' second output='.$second->getOutput().' '.$second->getErrorOutput());
        $firstInput->write($rollbackFirst ? "rollback\n" : "commit\n");
        $this->event($first, 'finished');
        $firstInput->close();
        $this->event($second, 'written');
        $secondInput->write("commit\n");
        $this->event($second, 'finished');
        $secondInput->close();
        foreach ([$first, $second] as $process) {
            $process->wait();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        }
    }

    /** @param array<string, int|string> $fixture */
    private function write(array $fixture): void
    {
        [$process, $input] = $this->start($fixture);
        $this->event($process, 'ready');
        $input->write("go\n");
        $this->event($process, 'written');
        $input->write("commit\n");
        $this->event($process, 'finished');
        $input->close();
        $process->wait();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /** @param array<string, int|string> $input
     * @return array{Process, InputStream}
     */
    private function start(array $input): array
    {
        $stream = new InputStream();
        $process = new Process([\PHP_BINARY, __DIR__.'/../../Support/Ingestion/concurrent-fact-revision.php'], env: ['APP_ENV' => 'test']);
        $process->setTimeout(20);
        $process->setInput($stream);
        $process->start();
        $this->children[] = $process;
        $stream->write(json_encode($input, \JSON_THROW_ON_ERROR)."\n");

        return [$process, $stream];
    }

    /** @return array<string, int|string> */
    private function event(Process $process, string $expected): array
    {
        $deadline = microtime(true) + 8;
        do {
            $running = $process->isRunning();
            foreach (explode("\n", trim($process->getOutput())) as $line) {
                if ('' === $line) {
                    continue;
                }
                $event = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
                if (\is_array($event) && $expected === ($event['event'] ?? null)) {
                    if ('ready' === $expected) {
                        self::assertIsInt($event['pid']);

                        return ['event' => $expected, 'pid' => $event['pid']];
                    }

                    return ['event' => $expected];
                }
            }
            self::assertTrue($running, $process->getErrorOutput());
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('Worker did not reach '.$expected.': '.$process->getErrorOutput());
    }

    /** @param array<string, int|string> $fixture
     * @return list<mixed>
     */
    private function column(array $fixture, string $column): array
    {
        $table = 'sales' === $fixture['kind'] ? 'sales_fact_revision' : 'marketplace_return_fact_revision';

        return $this->db->fetchFirstColumn('SELECT '.$column.' FROM '.$table.' WHERE company_id = ? ORDER BY fact_revision', [$fixture['companyId']]);
    }
}
