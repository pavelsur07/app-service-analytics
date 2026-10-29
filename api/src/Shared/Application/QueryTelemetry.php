<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\QueryTimingRecorder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** Bounded per-operation SQL aggregates. SQL text and parameters never leave the process.
 * @phpstan-type Group array{stage: string, hash: string, count: int, ns: int, max_ns: int, failures: int}
 * @phpstan-type State array{operation: string, company_id: ?string, account_id: ?string, request_id: ?string, started: int, stage: string, sql_count: int, sql_ns: int, queries: array<string, Group>, stages: array<string, array{count: int, ns: int}>, counters: array<string, int>}
 */
final class QueryTelemetry implements QueryTimingRecorder
{
    /** @var State|null */
    private ?array $active = null;

    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    public function enabled(): bool
    {
        return null !== $this->active;
    }

    /** @template T
     * @param \Closure(): T $operation
     *
     * @return T */
    public function run(string $name, string $companyId, string $accountId, string $requestId, \Closure $operation): mixed
    {
        $previous = $this->active;
        $this->active = ['operation' => $name, 'company_id' => self::id($companyId), 'account_id' => self::id($accountId), 'request_id' => self::id($requestId), 'started' => hrtime(true), 'stage' => 'capture', 'sql_count' => 0, 'sql_ns' => 0, 'queries' => [], 'stages' => [], 'counters' => []];
        $status = 'success';
        $errorClass = null;
        try {
            return $operation();
        } catch (\Throwable $error) {
            $status = 'failed';
            $errorClass = $error::class;
            throw $error;
        } finally {
            $state = $this->active;
            $this->active = $previous;
            $this->emit($state, $status, $errorClass);
        }
    }

    /** @template T
     * @param \Closure(): T $operation
     *
     * @return T */
    public function stage(string $name, \Closure $operation): mixed
    {
        if (null === $this->active) {
            return $operation();
        }
        $previous = $this->active['stage'];
        $name = 1 === preg_match('/^[a-z][a-z0-9_.]{0,63}$/D', $name) ? $name : 'other';
        if (!isset($this->active['stages'][$name]) && \count($this->active['stages']) >= 32) {
            $name = 'other';
        }
        $this->active['stage'] = $name;
        $started = hrtime(true);
        try {
            return $operation();
        } finally {
            $this->active['stage'] = $previous;
            $this->active['stages'][$name] ??= ['count' => 0, 'ns' => 0];
            ++$this->active['stages'][$name]['count'];
            $this->active['stages'][$name]['ns'] += hrtime(true) - $started;
        }
    }

    public function count(string $name, int $count): void
    {
        if (null !== $this->active && (isset($this->active['counters'][$name]) || \count($this->active['counters']) < 16)) {
            $this->active['counters'][$name] = ($this->active['counters'][$name] ?? 0) + $count;
        }
    }

    public function recordSql(string $sql, int $nanoseconds, bool $failed): void
    {
        if (null === $this->active) {
            return;
        }
        ++$this->active['sql_count'];
        $this->active['sql_ns'] += $nanoseconds;
        $hash = hash('sha256', $sql);
        $stage = $this->active['stage'];
        $key = $stage.':'.$hash;
        if (!isset($this->active['queries'][$key]) && \count($this->active['queries']) >= 64) {
            $key = 'other';
            $hash = '';
            $stage = 'other';
        }
        $this->active['queries'][$key] ??= ['stage' => $stage, 'hash' => $hash, 'count' => 0, 'ns' => 0, 'max_ns' => 0, 'failures' => 0];
        ++$this->active['queries'][$key]['count'];
        $this->active['queries'][$key]['ns'] += $nanoseconds;
        $this->active['queries'][$key]['max_ns'] = max($nanoseconds, $this->active['queries'][$key]['max_ns']);
        $this->active['queries'][$key]['failures'] += $failed ? 1 : 0;
    }

    /** @param State $state */
    private function emit(array $state, string $status, ?string $errorClass): void
    {
        $queries = array_values($state['queries']);
        usort($queries, static fn (array $a, array $b): int => $b['ns'] <=> $a['ns']);
        $groups = array_map(static fn (array $q): array => ['stage' => $q['stage'], 'hash' => $q['hash'], 'count' => $q['count'], 'sql_ms' => intdiv($q['ns'], 1000000), 'max_ms' => intdiv($q['max_ns'], 1000000), 'failures' => $q['failures']], \array_slice($queries, 0, 10));
        $stages = [];
        foreach ($state['stages'] as $stage => $timing) {
            $stages[$stage] = ['count' => $timing['count'], 'wall_ms' => intdiv($timing['ns'], 1000000)];
        }
        try {
            $this->logger->info('query_telemetry', ['operation' => $state['operation'], 'company_id' => $state['company_id'], 'account_id' => $state['account_id'], 'request_id' => $state['request_id'], 'status' => $status, 'error_class' => $errorClass, 'wall_ms' => intdiv(hrtime(true) - $state['started'], 1000000), 'sql_count' => $state['sql_count'], 'sql_ms' => intdiv($state['sql_ns'], 1000000), 'query_group_count' => \count($queries), 'queries' => $groups, 'stages' => $stages, 'counters' => $state['counters'], 'peak_bytes' => memory_get_peak_usage(true)]);
        } catch (\Throwable) {
            // An unavailable log sink must not alter the operation or hide its original error.
        }
    }

    private static function id(string $id): ?string
    {
        return 1 === preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id) ? $id : null;
    }
}
