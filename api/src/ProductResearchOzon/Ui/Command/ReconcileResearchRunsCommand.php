<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Command;

use App\ProductResearchOzon\Application\ReconcileResearchRunsAction;
use App\ProductResearchOzon\Domain\ValueObject\RunProgress;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:research:reconcile-runs', description: 'Повторно сверяет одну keyset-порцию запусков явно выбранной компании')]
final class ReconcileResearchRunsCommand extends Command
{
    public function __construct(private readonly ReconcileResearchRunsAction $reconcile)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('company-id', InputArgument::REQUIRED)->addOption('after', null, InputOption::VALUE_REQUIRED)->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Размер порции', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $company = $input->getArgument('company-id');
        $after = $input->getOption('after');
        $limit = $input->getOption('limit');
        if (!\is_string($company) || !RunProgress::validId($company) || (null !== $after && (!\is_string($after) || !RunProgress::validId($after))) || !\is_string($limit) || !ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 200) {
            return Command::INVALID;
        }
        try {
            $result = ($this->reconcile)($company, $after, (int) $limit);
            $output->writeln('attempted='.$result->attempted.' failed='.$result->failed.' next_cursor='.($result->nextCursor ?? '-'));

            return 0 === $result->failed ? Command::SUCCESS : Command::FAILURE;
        } catch (\Throwable $failure) {
            $output->writeln('reconciliation_failed class='.$failure::class);

            return Command::FAILURE;
        }
    }
}
