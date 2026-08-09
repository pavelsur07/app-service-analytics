<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Command;

use App\Ingestion\Application\DispatchActiveOzonSyncsAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Триггер расписания — собственный цикл, не symfony/scheduler и не cron
 * на хосте (docs/patterns.md): единственная периодическая задача сейчас,
 * абстракция ради одной задачи не оправдана. Обрыв контейнера посреди
 * тика не портит ничего — диспатч идемпотентен (FetchOzonPostingsHandler
 * целиком идемпотентен, ADR-006), поэтому цикл простой, без pcntl.
 *
 * Сама логика перечисления/диспатча — DispatchActiveOzonSyncsAction
 * (Application-слой): Deptrac не пускает IngestionUi к IdentityFacade,
 * эта команда до неё и не дотягивается.
 */
#[AsCommand(
    name: 'app:ingestion:schedule-ozon-sync',
    description: 'Периодически ставит в очередь синхронизацию за сегодня для всех активных Ozon-подключений',
)]
final class ScheduleOzonSyncCommand extends Command
{
    private const string LOCK_KEY = 'ingestion.schedule-ozon-sync';

    public function __construct(
        private readonly DispatchActiveOzonSyncsAction $dispatchActiveSyncs,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Пауза между тиками, секунды', '900')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Один проход без цикла — для ручного запуска и тестов');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $intervalOption */
        $intervalOption = $input->getOption('interval');
        $interval = (int) $intervalOption;
        $once = (bool) $input->getOption('once');

        while (true) {
            $dispatched = $this->tick();
            $io->writeln("Тик планировщика: поставлено сообщений — {$dispatched}.");

            if ($once) {
                break;
            }

            sleep($interval);
        }

        return Command::SUCCESS;
    }

    private function tick(): int
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY);
        if (!$lock->acquire()) {
            return 0;
        }

        try {
            return ($this->dispatchActiveSyncs)();
        } finally {
            $lock->release();
        }
    }
}
