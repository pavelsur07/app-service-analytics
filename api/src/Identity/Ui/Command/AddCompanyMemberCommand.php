<?php

declare(strict_types=1);

namespace App\Identity\Ui\Command;

use App\Identity\Application\AddCompanyMemberAction;
use App\Identity\Domain\ValueObject\CompanyMemberRole;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Доступ существующего пользователя ко второй компании. Ручная операция,
 * как и заведение учётной записи (ADR-007): самостоятельного управления
 * доступом сотрудников пока нет.
 *
 * companyId не проверяется на существование — по той же причине и с той же
 * платой, что в CreateUserCommand: «Поиск сущности компании по одному лишь
 * идентификатору запрещён» (CLAUDE.md §1), FK на company_id нет, опечатка
 * оператора создаст членство в никуда. Оператор видит введённый им
 * идентификатор в подтверждении.
 */
#[AsCommand(
    name: 'app:identity:add-company-member',
    description: 'Даёт существующему пользователю доступ к ещё одной компании',
)]
final class AddCompanyMemberCommand extends Command
{
    public function __construct(
        private readonly AddCompanyMemberAction $addCompanyMember,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email существующего пользователя')
            ->addArgument('companyId', InputArgument::REQUIRED, 'Идентификатор существующей компании')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Роль в company_member', CompanyMemberRole::Owner->value);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');
        /** @var string $companyIdArgument */
        $companyIdArgument = $input->getArgument('companyId');
        /** @var string $roleArgument */
        $roleArgument = $input->getOption('role');

        $role = CompanyMemberRole::tryFrom($roleArgument);
        if (null === $role) {
            $io->error(\sprintf('Неизвестная роль "%s".', $roleArgument));

            return Command::FAILURE;
        }

        try {
            $companyId = Uuid::fromString($companyIdArgument);
        } catch (\InvalidArgumentException) {
            $io->error(\sprintf('"%s" не UUID.', $companyIdArgument));

            return Command::FAILURE;
        }

        try {
            $member = ($this->addCompanyMember)($email, $companyId, $role);
        } catch (UniqueConstraintViolationException) {
            // Составной первичный ключ (company_id, user_id) — повторный
            // запуск перехватывается на вставке, а не проверкой перед ней
            // (CLAUDE.md §4).
            $io->note(\sprintf('Пользователь "%s" уже состоит в компании %s.', $email, $companyIdArgument));

            return Command::SUCCESS;
        }

        if (null === $member) {
            $io->error(\sprintf('Пользователя "%s" не существует — сначала заведите его через app:identity:create-user.', $email));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Пользователь "%s" добавлен в компанию %s с ролью %s.', $email, $companyIdArgument, $role->value));

        return Command::SUCCESS;
    }
}
