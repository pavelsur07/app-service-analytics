<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Exception\CommandNotFoundException;

/**
 * Сообщение, исчерпавшее ретраи, обязано остаться — не исчезнуть.
 *
 * Проверяется наличием команд messenger:failed:*, потому что Symfony
 * регистрирует их ровно тогда, когда задан failure_transport: убрать
 * транспорт из конфигурации, не уронив этот тест, нельзя.
 *
 * Тест ценой в две строки и написан по факту потери. Добор расходов
 * 14 августа 2026 потерял девять дней из десяти бесследно: очередь
 * пустая, данных нет, в логах ноль строк, подключение исправно.
 * Отсутствие failure-транспорта не ловится ничем — ни тайпчекером,
 * ни линтером, ни сборкой, — и замечается только тогда, когда что-то
 * уже потеряно.
 */
final class FailedMessagesAreKeptTest extends KernelTestCase
{
    public function testFailureTransportIsConfigured(): void
    {
        $application = new Application(self::bootKernel());

        try {
            $show = $application->find('messenger:failed:show');
            $retry = $application->find('messenger:failed:retry');
        } catch (CommandNotFoundException) {
            self::fail('failure_transport не задан: сообщение, исчерпавшее ретраи, удаляется молча.');
        }

        self::assertSame('messenger:failed:show', $show->getName());
        self::assertSame('messenger:failed:retry', $retry->getName());
    }
}
