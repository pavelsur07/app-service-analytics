<?php

declare(strict_types=1);

namespace App\Tests\Integration\SalesPlanning;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SalesPlanE2eHelperGuardTest extends TestCase
{
    public function testHelperRejectsTestAndProductionEnvironmentsBeforeAnySeed(): void
    {
        foreach (['test', 'prod'] as $environment) {
            $process = new Process([\PHP_BINARY, \dirname(__DIR__, 2).'/Support/SalesPlanning/e2e-cohort.php', 'seed', 'e2e@example.com'], env: ['APP_ENV' => $environment, 'CONWIX_E2E_PLANNING' => '1']);
            $process->run();
            self::assertSame(1, $process->getExitCode());
            self::assertStringContainsString('Only explicitly enabled local dev E2E is allowed.', $process->getErrorOutput());
            self::assertSame('', $process->getOutput());
        }
    }

    public function testHelperRequiresExplicitTestHarnessMarker(): void
    {
        $process = new Process([\PHP_BINARY, \dirname(__DIR__, 2).'/Support/SalesPlanning/e2e-cohort.php', 'seed', 'e2e@example.com'], env: ['APP_ENV' => 'dev', 'CONWIX_E2E_PLANNING' => '0']);
        $process->run();
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Only explicitly enabled local dev E2E is allowed.', $process->getErrorOutput());
        self::assertSame('', $process->getOutput());
    }
}
