<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class NetQualityCommandTest extends KernelTestCase
{
    public function testInvalidModeOptionsAndHashFailBeforeArtifactLookupWithoutLeakingPrivateFile(): void
    {
        $kernel = self::bootKernel(['debug' => false]);
        $tester = new CommandTester((new Application($kernel))->find('app:ingestion:net-forecast-quality'));
        $base = ['--company' => Uuid::v7()->toRfc4122(), '--account' => Uuid::v7()->toRfc4122(), '--operation' => Uuid::v7()->toRfc4122()];
        self::assertSame(2, $tester->execute(['mode' => 'evaluate', '--request-file' => '/absent-private-path', '--request-hash' => str_repeat('a', 64), ...$base]));
        self::assertStringNotContainsString('/absent-private-path', $tester->getDisplay());
        $path = tempnam(sys_get_temp_dir(), 'quality-command-');
        self::assertIsString($path);
        chmod($path, 0600);
        file_put_contents($path, '{"secretOrder":"never-print-me"}');
        try {
            self::assertSame(2, $tester->execute(['mode' => 'freeze', '--request-file' => $path, '--request-hash' => str_repeat('a', 64), ...$base]));
            self::assertStringNotContainsString('never-print-me', $tester->getDisplay());
            self::assertStringNotContainsString($path, $tester->getDisplay());
            self::assertSame(2, $tester->execute(['mode' => 'freeze', '--request-file' => 'https://invalid.local/private', '--request-hash' => str_repeat('a', 64), ...$base]));
        } finally {
            unlink($path);
        }
    }
}
