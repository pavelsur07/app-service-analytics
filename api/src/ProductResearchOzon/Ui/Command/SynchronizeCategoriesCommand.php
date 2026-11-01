<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Command;

use App\ProductResearchOzon\Application\SynchronizeCategoriesAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/** No cross-company enumeration, queue routing, cron or new scheduler transport. */
#[AsCommand(name: 'app:research:sync-categories', description: 'Обновляет локальный суточный кэш категорий явно выбранной компании')]
final class SynchronizeCategoriesCommand extends Command
{
    public function __construct(private readonly SynchronizeCategoriesAction $sync)
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
                $result = ($this->sync)($company);
                $output->writeln(\sprintf('catalog company=%s outcome=%s revision=%s categories=%d', $company, $result->outcome, $result->revision ?? '-', $result->categories));
                $status = \in_array($result->outcome, ['fresh', 'updated', 'busy'], true) ? Command::SUCCESS : Command::FAILURE;
            } catch (\Throwable $failure) {
                // Console boundary: credentials/response/DSN must not reach diagnostics.
                $output->writeln('catalog_sync_failed class='.$failure::class);
                $status = Command::FAILURE;
            }
            if (true === $input->getOption('once')) {
                return $status;
            }
            sleep((int) $interval);
        }
    }
}
