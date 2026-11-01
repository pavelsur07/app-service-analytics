<?php

declare(strict_types=1);

namespace App\Tests\Integration\ProductResearchOzon;

use App\ProductResearchOzon\Infrastructure\Query\ResearchProjectsQuery;
use App\Tests\Support\ProductResearchOzon\ResearchServices;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

final class ResearchConcurrencyTest extends KernelTestCase
{
    use ResearchServices;
    /** @var list<Process> */
    private array $children = [];
    /** @var list<string> */
    private array $companies = [];
    private Connection $db;
    private string $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::service(Connection::class);
        $database = $this->db->fetchOne('SELECT current_database()');
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
            foreach ($this->companies as $company) {
                $this->command(['operation' => 'cleanup', 'company' => $company]);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testTwoAddsReallyWaitAndOnlyOneVersionWins(): void
    {
        [$company, $id] = $this->seed();
        [$won, $lost] = $this->race($company, $id, 'add', 'add');
        self::assertSame('ok', $won['outcome']);
        self::assertSame('version_conflict', $lost['outcome']);
        $view = ResearchProjectsQuery::mapDetail(self::service(ResearchProjectsQuery::class)->detail($company, $id)->executeQuery()->fetchAllAssociative());
        self::assertNotNull($view);
        self::assertSame(2, $view->project->version);
        self::assertSame(2, $view->project->periodCount);
        self::assertCount(2, $view->periods);
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM audit_record WHERE company_id = :id', ['id' => $company]));
    }

    public function testCopyAndAddUseOneCoherentSourceInBothOrders(): void
    {
        [$company, $id] = $this->seed();
        [$copied, $added] = $this->race($company, $id, 'copy', 'add');
        self::assertSame('ok', $copied['outcome']);
        self::assertSame('ok', $added['outcome']);
        self::assertIsString($copied['id']);
        $view = ResearchProjectsQuery::mapDetail(self::service(ResearchProjectsQuery::class)->detail($company, $copied['id'])->executeQuery()->fetchAllAssociative());
        self::assertNotNull($view);
        self::assertCount(1, $view->periods);
        self::assertSame(2, ResearchProjectsQuery::mapDetail(self::service(ResearchProjectsQuery::class)->detail($company, $id)->executeQuery()->fetchAllAssociative())?->project->periodCount);
        [$company, $id] = $this->seed();
        [$added, $copied] = $this->race($company, $id, 'add', 'copy');
        self::assertSame('ok', $added['outcome']);
        self::assertSame('version_conflict', $copied['outcome']);
    }

    public function testWorkerRejectsWrongDatabaseBeforeWriting(): void
    {
        $company = Uuid::v7()->toRfc4122();
        [$child, $input] = $this->start(['operation' => 'seed', 'company' => $company, 'database' => 'app']);
        $input->close();
        $child->wait();
        self::assertSame(1, $child->getExitCode());
        self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM product_research_ozon_project WHERE company_id = :id', ['id' => $company]));
    }

    /** @return array{string,string} */
    private function seed(): array
    {
        $company = Uuid::v7()->toRfc4122();
        $this->companies[] = $company;
        $result = $this->command(['operation' => 'seed', 'company' => $company]);
        self::assertIsString($result['id']);

        return [$company, $result['id']];
    }

    /** @return array{array<string,mixed>, array<string,mixed>} */
    private function race(string $company, string $id, string $first, string $second): array
    {
        $request = ['company' => $company, 'id' => $id, 'version' => 1];
        [$winner, $release] = $this->start([...$request, 'operation' => $first, 'hold' => true]);
        $this->event($winner, 'prepared');
        $winnerStarted = $this->event($winner, 'started');
        self::assertIsInt($winnerStarted['pid']);
        [$loser, $input] = $this->start([...$request, 'operation' => $second]);
        $input->close();
        $started = $this->event($loser, 'started');
        self::assertIsInt($started['pid']);
        $deadline = hrtime(true) + 5000000000;
        do {
            $this->db->executeStatement('SELECT pg_stat_clear_snapshot()');
            $waiting = 'Lock' === $this->db->fetchOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = :pid AND CAST(:winner AS int) = ANY(pg_blocking_pids(pid))', ['pid' => $started['pid'], 'winner' => $winnerStarted['pid']]);
            if ($waiting) {
                break;
            }
            usleep(25000);
        } while (hrtime(true) < $deadline);
        self::assertTrue($waiting, 'Second real connection must wait on source header lock');
        $release->write("release\n");
        $release->close();
        $winner->wait();
        $loser->wait();
        self::assertSame(0, $winner->getExitCode(), $winner->getErrorOutput());
        self::assertSame(0, $loser->getExitCode(), $loser->getErrorOutput());

        return [$this->event($winner, 'result'), $this->event($loser, 'result')];
    }

    /** @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function command(array $request): array
    {
        [$child, $input] = $this->start($request);
        $input->close();
        $child->wait();
        self::assertSame(0, $child->getExitCode(), $child->getErrorOutput());

        return $this->event($child, 'result');
    }

    /** @param array<string,mixed> $request
     * @return array{Process,InputStream}
     */
    private function start(array $request): array
    {
        $input = new InputStream();
        $input->write(json_encode(['database' => $this->database, ...$request], \JSON_THROW_ON_ERROR)."\n");
        $child = new Process([\PHP_BINARY, \dirname(__DIR__, 2).'/Support/ProductResearchOzon/concurrent-research.php'], env: ['APP_ENV' => 'test', 'APP_DEBUG' => '1'], timeout: 20);
        $child->setInput($input);
        $child->start();
        $this->children[] = $child;

        return [$child, $input];
    }

    /** @return array<string,mixed> */
    private function event(Process $process, string $event): array
    {
        $deadline = hrtime(true) + 15000000000;
        do {
            $lines = explode("\n", $process->getOutput());
            array_pop($lines);
            foreach ($lines as $line) {
                $row = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
                self::assertIsArray($row);
                if (($row['event'] ?? null) === $event) {
                    /* @var array<string,mixed> $row */
                    return $row;
                }
            }
            if (!$process->isRunning()) {
                break;
            }
            usleep(10000);
        } while (hrtime(true) < $deadline);
        self::fail('Missing '.$event.': '.$process->getErrorOutput());
    }
}
