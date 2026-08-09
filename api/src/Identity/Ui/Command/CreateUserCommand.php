<?php

declare(strict_types=1);

namespace App\Identity\Ui\Command;

use App\Identity\Application\CreateUserWithMembershipAction;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\CompanyMemberRole;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Ручной онбординг (ADR-007) — саморегистрация отложена, это единственный
 * способ завести учётку на текущей стадии. Та же команда — механизм
 * бэкфилла для уже существующих компаний: разовый запуск с реальными
 * данными владельца, не отдельная data-миграция (план —
 * /home/deploy/.claude/plans/rippling-churning-scroll.md, «Бэкфилл
 * механизм — вариант А»).
 */
#[AsCommand(
    name: 'app:identity:create-user',
    description: 'Создаёт пользователя и членство в существующей компании',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly CreateUserWithMembershipAction $createUserWithMembership,
        private readonly CompanyRepository $companies,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email пользователя')
            ->addArgument('password', InputArgument::REQUIRED, 'Пароль в открытом виде')
            ->addArgument('companyId', InputArgument::REQUIRED, 'Идентификатор существующей компании')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Роль в company_member', CompanyMemberRole::Owner->value);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');
        /** @var string $plainPassword */
        $plainPassword = $input->getArgument('password');
        /** @var string $companyIdArgument */
        $companyIdArgument = $input->getArgument('companyId');
        /** @var string $roleArgument */
        $roleArgument = $input->getOption('role');

        $role = CompanyMemberRole::tryFrom($roleArgument);
        if (null === $role) {
            $io->error(\sprintf('Неизвестная роль "%s".', $roleArgument));

            return Command::FAILURE;
        }

        if (null === $this->companies->get($companyIdArgument)) {
            $io->error(\sprintf('Компания "%s" не найдена.', $companyIdArgument));

            return Command::FAILURE;
        }

        // Хэш вычисляется здесь, не в Action и не в Entity — User хранит
        // только готовый хэш (симметрично MarketplaceCredentialsEncryptor).
        // Хэшеру нужен объект PasswordAuthenticatedUserInterface только для
        // выбора алгоритма по конфигурации — вспомогательный экземпляр
        // никуда не сохраняется, реальный User создаёт Action ниже.
        $passwordHash = $this->passwordHasher->hashPassword(User::register($email, ''), $plainPassword);

        try {
            $user = ($this->createUserWithMembership)($email, $passwordHash, Uuid::fromString($companyIdArgument), $role);
        } catch (UniqueConstraintViolationException) {
            // Повторный запуск с тем же email (CLAUDE.md §4 — перехват
            // конфликта на вставке, не проверка перед ней).
            $io->note(\sprintf('Пользователь "%s" уже существует — повторный запуск не создаёт дубль.', $email));

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Пользователь "%s" создан. userId=%s companyId=%s role=%s', $email, $user->id(), $companyIdArgument, $role->value));

        return Command::SUCCESS;
    }
}
