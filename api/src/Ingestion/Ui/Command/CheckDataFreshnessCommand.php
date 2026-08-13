<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Command;

use App\Ingestion\Application\NotifyStaleAccountsAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Отдельный процесс, не вторая обязанность внутри ScheduleOzonSyncCommand.
 * Причина не в чистоте: сторож, живущий внутри сторожимого процесса,
 * молчит ровно тогда, когда должен кричать. Умер worker-scheduler —
 * синхронизации нет и писем о том, что её нет, тоже нет, а тишина
 * неотличима от здоровья.
 *
 * Цикл собственный, как у ScheduleOzonSyncCommand: symfony/scheduler
 * и cron на хосте по-прежнему не нужны (docs/patterns.md). Замка нет
 * намеренно — повторы подавляет сам Action, посуточно на подключение,
 * и второй экземпляр процесса ничего не испортит.
 *
 * Кто сторожит сторожа: внешняя проверка доступности приложения
 * (docs/operations-checklist.md) — она снаружи и не зависит ни от одного
 * нашего процесса. В этот пакет не входит.
 */
#[AsCommand(
    name: 'app:ingestion:check-data-freshness',
    description: 'Письмо, если по активному подключению давно нет новых данных',
)]
final class CheckDataFreshnessCommand extends Command
{
    public function __construct(
        private readonly NotifyStaleAccountsAction $notifyStaleAccounts,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Пауза между проверками, секунды', '3600')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Одна проверка без цикла — для ручного запуска и тестов');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $intervalOption */
        $intervalOption = $input->getOption('interval');
        $interval = (int) $intervalOption;
        $once = (bool) $input->getOption('once');

        while (true) {
            $alerted = ($this->notifyStaleAccounts)();
            $io->writeln([] === $alerted
                ? 'Проверка свежести: несвежих подключений нет.'
                : 'Проверка свежести: письмо отправлено по '.implode(', ', $alerted).'.');

            if ($once) {
                break;
            }

            sleep($interval);
        }

        return Command::SUCCESS;
    }
}
