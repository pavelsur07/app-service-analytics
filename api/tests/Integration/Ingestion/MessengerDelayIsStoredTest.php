<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use Doctrine\DBAL\Connection as DbalConnection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as TransportConnection;

/**
 * Выдержка ретрая доходит до базы, а не теряется по дороге.
 *
 * Стратегия считает задержку правильно — это держит
 * MessengerRetryDelayTest. Но между расчётом и делом есть второе звено:
 * задержка обязана превратиться в `available_at` в будущем, иначе
 * воркер заберёт сообщение следующим же опросом, и выдержка окажется
 * фикцией.
 *
 * Проверяется на настоящем doctrine-транспорте и настоящей таблице:
 * подмена здесь обессмыслила бы тест, потому что предмет проверки —
 * ровно то, что делает транспорт с колонкой.
 */
final class MessengerDelayIsStoredTest extends KernelTestCase
{
    private const string QUEUE = 'retry_probe';

    protected function tearDown(): void
    {
        $this->dbal()->executeStatement('DELETE FROM messenger_messages WHERE queue_name = ?', [self::QUEUE]);

        parent::tearDown();
    }

    public function testDelayBecomesFutureAvailableAt(): void
    {
        $transport = $this->transport();

        $transport->send('{}', [], 30_000);

        $row = $this->probeRow();
        self::assertNotFalse($row);

        // Тридцать секунд, а не ноль: сообщение не должно быть доступно
        // сразу же — в этом весь смысл выдержки.
        self::assertSame(30, $this->delaySeconds($row));
    }

    public function testWithoutDelayMessageIsAvailableAtOnce(): void
    {
        $this->transport()->send('{}', [], 0);

        $row = $this->probeRow();
        self::assertNotFalse($row);

        self::assertSame(0, $this->delaySeconds($row));
    }

    /**
     * Третье звено: мало записать срок в будущем — его должен уважать
     * и тот, кто забирает. Если забирает раньше срока, выдержка не
     * работает, сколько её ни настраивай.
     */
    public function testDelayedMessageIsNotPickedUpBeforeItsTime(): void
    {
        $transport = $this->transport();

        $transport->send('{}', [], 30_000);

        self::assertNull($transport->get(), 'Сообщение с выдержкой забрано раньше срока.');
    }

    public function testMessageWithoutDelayIsPickedUpAtOnce(): void
    {
        $transport = $this->transport();

        $transport->send('{}', [], 0);

        self::assertNotNull($transport->get());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function delaySeconds(array $row): int
    {
        $created = $row['created_at'];
        $available = $row['available_at'];
        self::assertIsString($created);
        self::assertIsString($available);

        return (new \DateTimeImmutable($available))->getTimestamp()
            - (new \DateTimeImmutable($created))->getTimestamp();
    }

    /**
     * @return array<string, mixed>|false
     */
    private function probeRow(): array|false
    {
        return $this->dbal()->fetchAssociative(
            'SELECT created_at, available_at FROM messenger_messages WHERE queue_name = ? ORDER BY id DESC LIMIT 1',
            [self::QUEUE],
        );
    }

    private function transport(): TransportConnection
    {
        return new TransportConnection(
            ['table_name' => 'messenger_messages', 'queue_name' => self::QUEUE, 'auto_setup' => false],
            $this->dbal(),
        );
    }

    private function dbal(): DbalConnection
    {
        self::bootKernel();

        /** @var DbalConnection $connection */
        $connection = self::getContainer()->get(DbalConnection::class);

        return $connection;
    }
}
