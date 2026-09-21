<?php

declare(strict_types=1);

namespace App\Planning\Ui\Command;

use App\Planning\Application\CleanupExpiredPlanImportsAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:planning:imports:cleanup', description: 'Удаляет истёкшие preview и старые результаты импорта порциями.')]
final class CleanupExpiredPlanImportsCommand extends Command
{
    public function __construct(private readonly CleanupExpiredPlanImportsAction $cleanup)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = ($this->cleanup)();
        (new SymfonyStyle($input, $output))->success(\sprintf('Удалено preview: %d.', $deleted));

        return Command::SUCCESS;
    }
}
