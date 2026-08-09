<?php

declare(strict_types=1);

namespace App\Identity\Ui\Command;

use App\Identity\Application\CreateUserWithMembershipAction;
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
 *
 * companyId не проверяется на существование: «Поиск сущности компании
 * по одному лишь идентификатору запрещён» (CLAUDE.md §1) — метода вида
 * CompanyRepository::get($id) без companyId-контекста в репозитории
 * не существует. Опечатка оператора создаст членство в никуда молча
 * (нет FK на company_id — тот же выбор, что для marketplace_account),
 * но это ручная команда с оператором, видящим введённый им companyId
 * в подтверждении на экране.
 */
#[AsCommand(
    name: 'app:identity:create-user',
    description: 'Создаёт пользователя и членство в существующей компании',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly CreateUserWithMembershipAction $createUserWithMembership,
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

        // Только формат — не поиск сущности по идентификатору (CLAUDE.md
        // §1 запрещает именно это, см. класс-докблок), существование
        // компании не проверяется.
        try {
            $companyId = Uuid::fromString($companyIdArgument);
        } catch (\InvalidArgumentException) {
            $io->error(\sprintf('"%s" не UUID.', $companyIdArgument));

            return Command::FAILURE;
        }

        // Хэш вычисляется здесь, не в Action и не в Entity — User хранит
        // только готовый хэш (симметрично MarketplaceCredentialsEncryptor).
        // Хэшеру нужен объект PasswordAuthenticatedUserInterface только для
        // выбора алгоритма по конфигурации — вспомогательный экземпляр
        // никуда не сохраняется, реальный User создаёт Action ниже.
        $passwordHash = $this->passwordHasher->hashPassword(User::register($email, ''), $plainPassword);

        try {
            $user = ($this->createUserWithMembership)($email, $passwordHash, $companyId, $role);
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
