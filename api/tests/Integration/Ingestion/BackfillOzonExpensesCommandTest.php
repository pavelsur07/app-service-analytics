<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\Message\FetchOzonExpensesMessage;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Команда только диспатчит — загрузку и её идемпотентность проверяет
 * FetchOzonExpensesHandlerTest. Здесь важны две вещи: диапазон
 * разворачивается в день на сообщение включительно с обеих сторон,
 * и ни один отказ на разборе аргументов не оставляет в очереди половину
 * задания.
 */
final class BackfillOzonExpensesCommandTest extends KernelTestCase
{
    private const string COMPANY_ID = '019fe6ea-cd6a-7c81-a869-883a0a562b47';

    private const string ACCOUNT_ID = '019fe6ea-cd99-7af8-bf4a-623a5a31cf7b';

    public function testRangeBecomesOneMessagePerDayInclusive(): void
    {
        $tester = $this->commandTester();
        $transport = $this->transport();

        $tester->execute($this->arguments('2026-08-02', '2026-08-04'));

        $tester->assertCommandIsSuccessful();

        // Три сообщения, а не два: обе границы входят в диапазон.
        // Полуоткрытый интервал молча терял бы последний день, и дыра
        // в расходах осталась бы ровно там, где её труднее всего заметить.
        self::assertSame(
            ['2026-08-02', '2026-08-03', '2026-08-04'],
            $this->dispatchedDays($transport),
        );
    }

    public function testSingleDayRangeIsOneMessage(): void
    {
        $tester = $this->commandTester();
        $transport = $this->transport();

        $tester->execute($this->arguments('2026-08-02', '2026-08-02'));

        $tester->assertCommandIsSuccessful();
        self::assertSame(['2026-08-02'], $this->dispatchedDays($transport));

        $envelopes = $transport->getSent();
        $message = $envelopes[0]->getMessage();
        self::assertInstanceOf(FetchOzonExpensesMessage::class, $message);
        self::assertSame(self::COMPANY_ID, $message->companyId);
        self::assertSame(self::ACCOUNT_ID, $message->marketplaceAccountId);
    }

    public function testCalendarlessDateIsRejectedWithoutDispatching(): void
    {
        $tester = $this->commandTester();
        $transport = $this->transport();

        // 30 февраля разбирается по форме и переезжает на 2 марта —
        // без проверки оператор получил бы диапазон не тот, что просил.
        $exitCode = $tester->execute($this->arguments('2026-02-30', '2026-03-02'));

        self::assertSame(1, $exitCode);
        self::assertCount(0, $transport->getSent());
    }

    public function testReversedRangeIsRejectedWithoutDispatching(): void
    {
        $tester = $this->commandTester();
        $transport = $this->transport();

        $exitCode = $tester->execute($this->arguments('2026-08-04', '2026-08-02'));

        self::assertSame(1, $exitCode);
        self::assertCount(0, $transport->getSent());
    }

    public function testRangeBeyondTheCeilingIsRejectedWithoutDispatching(): void
    {
        $tester = $this->commandTester();
        $transport = $this->transport();

        // Отказ, а не обрезка до потолка: день — это запрос к площадке,
        // и опечатка в годе иначе ушла бы в очередь первыми 180 днями
        // чужого диапазона.
        $exitCode = $tester->execute($this->arguments('2020-08-02', '2026-08-02'));

        self::assertSame(1, $exitCode);
        self::assertCount(0, $transport->getSent());
    }

    /**
     * @return array<string, string>
     */
    private function arguments(string $from, string $to): array
    {
        return [
            'companyId' => self::COMPANY_ID,
            'marketplaceAccountId' => self::ACCOUNT_ID,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * @return list<string>
     */
    private function dispatchedDays(InMemoryTransport $transport): array
    {
        $days = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(FetchOzonExpensesMessage::class, $message);
            $days[] = $message->accrualDate;
        }

        return $days;
    }

    private function commandTester(): CommandTester
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $command = $application->find('app:ingestion:backfill-ozon-expenses');

        return new CommandTester($command);
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_ingestion');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
