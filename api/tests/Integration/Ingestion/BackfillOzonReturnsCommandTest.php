<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\Message\FetchOzonReturnsMessage;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class BackfillOzonReturnsCommandTest extends KernelTestCase
{
    private const string COMPANY_ID = '019fe6ea-cd6a-7c81-a869-883a0a562b47';
    private const string ACCOUNT_ID = '019fe6ea-cd99-7af8-bf4a-623a5a31cf7b';

    public function testRangeIsSplitIntoSequentialWindowsOfAtMostNinetyDays(): void
    {
        $tester = $this->commandTester();
        $tester->execute($this->arguments('2026-01-01', '2026-08-30'));
        $tester->assertCommandIsSuccessful();

        self::assertSame([
            ['2026-01-01', '2026-03-31'],
            ['2026-04-01', '2026-06-29'],
            ['2026-06-30', '2026-08-30'],
        ], $this->ranges());
    }

    public function testRangeOverThreeHundredSixtyFiveDaysIsRejectedBeforeDispatch(): void
    {
        $tester = $this->commandTester();
        $exitCode = $tester->execute($this->arguments('2025-09-01', '2026-09-01'));

        self::assertSame(1, $exitCode);
        self::assertCount(0, $this->transport()->getSent());
    }

    public function testInvalidOrReversedRangeIsRejectedBeforeDispatch(): void
    {
        $tester = $this->commandTester();
        self::assertSame(1, $tester->execute($this->arguments('2026-02-30', '2026-03-01')));
        self::assertCount(0, $this->transport()->getSent());
    }

    /**
     * @return array<string, string>
     */
    private function arguments(string $from, string $to): array
    {
        return [
            'companyId' => self::COMPANY_ID,
            'marketplaceAccountId' => self::ACCOUNT_ID,
            '--from' => $from,
            '--to' => $to,
        ];
    }

    /**
     * @return list<array{string, string}>
     */
    private function ranges(): array
    {
        $ranges = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(FetchOzonReturnsMessage::class, $message);
            self::assertSame(self::COMPANY_ID, $message->companyId);
            self::assertSame(self::ACCOUNT_ID, $message->marketplaceAccountId);
            $ranges[] = [$message->from, $message->to];
        }

        return $ranges;
    }

    private function commandTester(): CommandTester
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        return new CommandTester($application->find('app:ingestion:backfill-ozon-returns'));
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_ingestion');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
