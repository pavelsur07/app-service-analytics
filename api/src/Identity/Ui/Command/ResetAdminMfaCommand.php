<?php

declare(strict_types=1);

namespace App\Identity\Ui\Command;

use App\Identity\Domain\AdministratorRepository;
use App\Identity\Domain\AdminMfaRepository;
use App\Identity\Domain\ValueObject\AdminRole;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Только SSH/CLI после внешней проверки личности, не вызывается из HTTP. */
#[AsCommand(name: 'app:identity:reset-admin-mfa', description: 'Операторское восстановление MFA после проверки личности (ADR-045)')]
final class ResetAdminMfaCommand extends Command
{
    public function __construct(
        private readonly AdministratorRepository $administrators,
        private readonly AdminMfaRepository $mfa,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Администратор, чья личность проверена');
        $this->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Email SuperAdmin, подтвердившего восстановление');
        $this->addOption('identity-reference', null, InputOption::VALUE_REQUIRED, 'Несекретная ссылка/номер внешней проверки личности');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Применить сброс после завершения проверки личности');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        $operatorEmail = $input->getOption('operator');
        $reference = $input->getOption('identity-reference');
        if (!\is_string($email) || !\is_string($operatorEmail) || !\is_string($reference) || 1 !== preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{2,127}\z/', $reference)) {
            $io->error('Укажите администратора, оператора и несекретный номер проверки личности.');

            return Command::INVALID;
        }
        $administrator = $this->administrators->findByEmail($email);
        $operator = $this->administrators->findByEmail($operatorEmail);
        if (null === $administrator || null === $operator || AdminRole::SuperAdmin !== $operator->role()) {
            $io->error('Нужны существующий администратор и оператор SuperAdmin.');

            return Command::FAILURE;
        }
        if (0 === $this->mfa->state($administrator->id())->version) {
            $io->error('MFA ещё не настраивался; используйте обычный вход и enrollment.');

            return Command::FAILURE;
        }
        if (true !== $input->getOption('apply')) {
            $io->note('Изменений нет. После внешней проверки личности повторите с --apply. Прежние сессии и резервные коды будут отменены.');

            return Command::SUCCESS;
        }
        $this->mfa->reset($administrator->id(), $operator->id(), $reference, $this->clock->now());
        $io->success('MFA сброшен, прежние сессии и коды отменены. Требуется вход по паролю и настройка нового фактора.');

        return Command::SUCCESS;
    }
}
