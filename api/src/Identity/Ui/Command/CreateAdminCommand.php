<?php

declare(strict_types=1);

namespace App\Identity\Ui\Command;

use App\Identity\Domain\Administrator;
use App\Identity\Domain\AdministratorRepository;
use App\Identity\Domain\ValueObject\AdminRole;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Единственный способ завести `SuperAdmin` (ADR-017): HTTP-маршрута
 * для верхней роли нет и не появится — ни для первого администратора,
 * ни для последующих. Форма в админке заводит только `Admin`
 * и доступна только `SuperAdmin`.
 *
 * Почему консоль, а не «первый вход создаёт админа»: сценарий
 * самопровозглашения нельзя закрыть — маршрут либо есть, либо нет,
 * а «есть, пока таблица пуста» держится на состоянии данных, то есть
 * на том, что кто-то не успел раньше.
 *
 * createdByAdminId у заведённого этой командой — null: автора в системе
 * нет, оператор известен из контекста запуска (доступ к боевому серверу
 * по отдельному ключу, «Периметр автономной работы»).
 */
#[AsCommand(
    name: 'app:identity:create-admin',
    description: 'Создаёт администратора сервиса (единственный путь к роли super_admin)',
)]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly AdministratorRepository $administrators,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email администратора')
            ->addArgument('password', InputArgument::REQUIRED, 'Пароль в открытом виде')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Роль: admin или super_admin', AdminRole::Admin->value);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');
        /** @var string $plainPassword */
        $plainPassword = $input->getArgument('password');
        /** @var string $roleArgument */
        $roleArgument = $input->getOption('role');

        $role = AdminRole::tryFrom($roleArgument);
        if (null === $role) {
            $io->error(\sprintf('Неизвестная роль "%s". Допустимо: %s.', $roleArgument, implode(', ', array_column(AdminRole::cases(), 'value'))));

            return Command::FAILURE;
        }

        // Хэш считается здесь, как и в CreateUserCommand: сущность хранит
        // готовый хэш. Хэшеру нужен объект только для выбора алгоритма
        // по конфигурации — вспомогательный экземпляр никуда не сохраняется.
        $passwordHash = $this->passwordHasher->hashPassword(
            Administrator::create($email, '', $role, null),
            $plainPassword,
        );

        $administrator = Administrator::create($email, $passwordHash, $role, null);

        try {
            $this->administrators->add($administrator);
        } catch (UniqueConstraintViolationException) {
            // Перехват конфликта на вставке, не проверка перед ней
            // (CLAUDE.md §4). Роль повторный запуск не меняет: смены
            // роли в системе нет вовсе.
            $io->note(\sprintf('Администратор "%s" уже существует — повторный запуск не создаёт дубль и не меняет роль.', $email));

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Администратор "%s" создан. adminId=%s role=%s', $administrator->email(), $administrator->id(), $role->value));

        return Command::SUCCESS;
    }
}
