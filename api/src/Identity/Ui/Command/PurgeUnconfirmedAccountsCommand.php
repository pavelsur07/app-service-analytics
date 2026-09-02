<?php

declare(strict_types=1);

namespace App\Identity\Ui\Command;

use App\Identity\Application\PurgeUnconfirmedAccountsAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Только ручной запуск: автоматическое удаление арендатора не планируется.
 */
#[AsCommand(
    name: 'app:identity:purge-unconfirmed-accounts',
    description: 'Удаляет пустые неподтверждённые аккаунты старше 30 дней',
)]
final class PurgeUnconfirmedAccountsCommand extends Command
{
    public function __construct(
        private readonly PurgeUnconfirmedAccountsAction $purge,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $cutoff = $now->modify('-30 days');
        $deleted = ($this->purge)($cutoff);

        $io->writeln('Граница создания: '.$cutoff->format(\DATE_ATOM));
        $io->success(\sprintf('Удалено компаний: %d', $deleted));

        return Command::SUCCESS;
    }
}
