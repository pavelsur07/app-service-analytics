<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\Message\FetchOzonAdCampaignStatsMessage;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Разовый добор рекламы (ADR-026 п. 4) — история: куски по 30 дней
 * с отчётами уходят в очередь истории, а не перед тиком всех кабинетов
 * (ADR-027).
 */
final class BackfillOzonAdvertisingCommandTest extends KernelTestCase
{
    private const string COMPANY_ID = '019fe6ea-cd6a-7c81-a869-883a0a562b47';

    private const string ACCOUNT_ID = '019fe6ea-cd99-7af8-bf4a-623a5a31cf7b';

    public function testYearBecomesChunksWithReportsInTheHistoryQueue(): void
    {
        $tester = $this->commandTester();

        $tester->execute([
            'companyId' => self::COMPANY_ID,
            'marketplaceAccountId' => self::ACCOUNT_ID,
            'from' => '2025-09-25',
            'to' => '2026-09-24',
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([], [...$this->transport('async_ingestion')->getSent()]);

        $chunks = [];
        foreach ($this->transport('async_backfill')->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(FetchOzonAdCampaignStatsMessage::class, $message);
            self::assertSame(self::ACCOUNT_ID, $message->marketplaceAccountId);
            self::assertTrue($message->withReports);
            $chunks[] = [$message->from, $message->to];
        }
        self::assertCount(13, $chunks);
        self::assertSame('2026-09-24', $chunks[0][1]);
        self::assertSame('2025-09-25', $chunks[12][0]);
    }

    public function testRangeLongerThanAYearIsRefused(): void
    {
        $tester = $this->commandTester();

        $tester->execute([
            'companyId' => self::COMPANY_ID,
            'marketplaceAccountId' => self::ACCOUNT_ID,
            'from' => '2025-01-01',
            'to' => '2026-09-24',
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame([], [...$this->transport('async_backfill')->getSent()]);
    }

    private function commandTester(): CommandTester
    {
        $kernel = self::bootKernel();

        return new CommandTester((new Application($kernel))->find('app:ingestion:backfill-ozon-advertising'));
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
