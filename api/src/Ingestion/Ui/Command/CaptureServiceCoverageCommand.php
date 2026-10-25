<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Command;

use App\Ingestion\Application\ServiceCoverage\CaptureServiceCoverageAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Снятие сводки покрытия ozon-fetcher по расписанию для экрана
 * «Покрытие GO» (ADR-043 п. 6). Отдельный процесс worker-service-coverage:
 * экран читает сохранённую сводку и сервис не вызывает
 * (services/AGENTS.md §2, решение владельца 25.10.2026).
 *
 * Цикл собственный, как у CheckDataFreshnessCommand: symfony/scheduler
 * и cron не нужны (docs/patterns.md). Замка нет — запись идемпотентна
 * (upsert по сервису), второй экземпляр только перезапишет ту же строку.
 * Остановленный процесс экран видит по времени последней попытки.
 */
#[AsCommand(
    name: 'app:ingestion:capture-service-coverage',
    description: 'Снять сводку покрытия ozon-fetcher для экрана «Покрытие GO»',
)]
final class CaptureServiceCoverageCommand extends Command
{
    public function __construct(
        private readonly CaptureServiceCoverageAction $capture,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Пауза между снятиями, секунды', '300')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Одно снятие без цикла — для ручного запуска и тестов');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $intervalOption */
        $intervalOption = $input->getOption('interval');
        $interval = (int) $intervalOption;
        $once = (bool) $input->getOption('once');

        while (true) {
            $state = ($this->capture)(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $output->writeln('Сводка покрытия ozon-fetcher: '.$state->value.'.');

            if ($once) {
                break;
            }

            sleep($interval);
        }

        return Command::SUCCESS;
    }
}
