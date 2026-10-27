<?php

declare(strict_types=1);

namespace App\Tests\Integration\SalesPlanning;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SalesPlanBenchmarkGuardTest extends TestCase
{
    public function testBenchmarkRejectsUnsafeEnvironmentAndMissingMarkerBeforeConnecting(): void
    {
        foreach ([['test', '1'], ['prod', '1'], ['dev', '0']] as [$environment, $marker]) {
            foreach (['seed', 'explain', 'cleanup'] as $operation) {
                $process = new Process([\PHP_BINARY, \dirname(__DIR__, 2).'/Support/SalesPlanning/benchmark-performance.php', $operation], env: ['APP_ENV' => $environment, 'CONWIX_BENCHMARK_PLANNING' => $marker]);
                $process->run();
                self::assertSame(1, $process->getExitCode());
                self::assertStringContainsString('Only explicitly enabled local dev planning benchmark is allowed.', $process->getErrorOutput());
                self::assertSame('', $process->getOutput());
            }
        }
    }
}
