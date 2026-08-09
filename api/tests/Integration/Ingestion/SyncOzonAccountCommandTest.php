<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Команда только диспатчит — обработку и идемпотентность проверяет
 * FetchOzonPostingsHandlerTest. Здесь важно, что аргументы попадают
 * в сообщение на правильные позиции и невалидная дата не уходит в очередь.
 */
final class SyncOzonAccountCommandTest extends KernelTestCase
{
    public function testDispatchesMessageWithGivenArguments(): void
    {
        $tester = $this->commandTester();
        $transport = $this->transport();

        $tester->execute([
            'companyId' => '019fe6ea-cd6a-7c81-a869-883a0a562b47',
            'marketplaceAccountId' => '019fe6ea-cd99-7af8-bf4a-623a5a31cf7b',
            'businessDate' => '2026-08-09',
        ]);

        $tester->assertCommandIsSuccessful();

        $envelopes = $transport->getSent();
        self::assertCount(1, $envelopes);

        $message = $envelopes[0]->getMessage();
        self::assertInstanceOf(FetchOzonPostingsMessage::class, $message);
        self::assertSame('019fe6ea-cd6a-7c81-a869-883a0a562b47', $message->companyId);
        self::assertSame('019fe6ea-cd99-7af8-bf4a-623a5a31cf7b', $message->marketplaceAccountId);
        self::assertSame('2026-08-09', $message->businessDate);
    }

    public function testInvalidBusinessDateIsRejectedWithoutDispatching(): void
    {
        $tester = $this->commandTester();
        $transport = $this->transport();

        $exitCode = $tester->execute([
            'companyId' => '019fe6ea-cd6a-7c81-a869-883a0a562b47',
            'marketplaceAccountId' => '019fe6ea-cd99-7af8-bf4a-623a5a31cf7b',
            'businessDate' => '09.08.2026',
        ]);

        self::assertSame(1, $exitCode);
        self::assertCount(0, $transport->getSent());
    }

    private function commandTester(): CommandTester
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $command = $application->find('app:ingestion:sync-ozon-account');

        return new CommandTester($command);
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_ingestion');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
