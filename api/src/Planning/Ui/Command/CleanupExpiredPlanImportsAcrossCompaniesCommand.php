<?php

declare(strict_types=1);

namespace App\Planning\Ui\Command;

use App\Planning\Application\CleanupExpiredPlanImportsAcrossCompaniesAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(name: 'app:planning:imports:cleanup', description: 'Удаляет истёкшие preview и старые результаты импорта порциями.')]
/** Операционная межарендаторная команда по CLAUDE.md §1. */
final class CleanupExpiredPlanImportsAcrossCompaniesCommand extends Command
{
    private const string LOCK_KEY = 'planning.imports.cleanup';

    public function __construct(
        private readonly CleanupExpiredPlanImportsAcrossCompaniesAction $cleanup,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Пауза между очистками, секунды; без опции выполняется один проход');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $intervalOption = $input->getOption('interval');
        \assert(null === $intervalOption || \is_string($intervalOption));
        $interval = null === $intervalOption ? null : (int) $intervalOption;
        if (null !== $interval && $interval < 1) {
            $io->error('Интервал должен быть положительным числом секунд.');

            return Command::INVALID;
        }

        do {
            $deleted = $this->tick();
            $io->writeln(\sprintf('Очистка preview: удалено %d.', $deleted));
            if (null !== $interval) {
                sleep($interval);
            }
        } while (null !== $interval);

        return Command::SUCCESS;
    }

    private function tick(): int
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY, 300.0);
        if (!$lock->acquire()) {
            return 0;
        }

        try {
            $total = 0;
            do {
                $deleted = ($this->cleanup)();
                $total += $deleted;
                $lock->refresh(300.0);
            } while (CleanupExpiredPlanImportsAcrossCompaniesAction::BATCH_SIZE === $deleted);

            return $total;
        } finally {
            $lock->release();
        }
    }
}
