<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Command;

use App\ProductResearchOzon\Application\ProcessResearchRunsAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/** No cross-company enumeration, queue routing, cron or new scheduler transport. */
#[AsCommand(name: 'app:research:process-runs', description: 'Доставляет задания и обновляет прогресс явно выбранной компании')]
final class ProcessResearchRunsCommand extends Command
{
    public function __construct(private readonly ProcessResearchRunsAction $sync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('company-id', InputArgument::REQUIRED, 'UUIDv7 компании')->addOption('once', null, InputOption::VALUE_NONE, 'Один проход')->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Интервал фонового цикла, секунды', '60');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $company = $input->getArgument('company-id');
        $interval = $input->getOption('interval');
        if (!\is_string($company) || !Uuid::isValid($company) || !(Uuid::fromString($company) instanceof UuidV7) || !\is_string($interval) || !ctype_digit($interval) || (int) $interval < 1 || (int) $interval > 3600) {
            $output->writeln('invalid_company_or_interval');

            return Command::INVALID;
        }
        $company = Uuid::fromString($company)->toRfc4122();
        while (true) {
            try {
                ($this->sync)($company);
                $output->writeln('research_runs_processed company='.$company);
                $status = Command::SUCCESS;
            } catch (\Throwable $failure) {
                // Console boundary: credentials/response/DSN must not reach diagnostics.
                $output->writeln('research_runs_failed class='.$failure::class);
                $status = Command::FAILURE;
            }
            if (true === $input->getOption('once')) {
                return $status;
            }
            sleep((int) $interval);
        }
    }
}
