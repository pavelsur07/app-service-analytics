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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Единственный способ завести `SuperAdmin` (ADR-017): HTTP-маршрута
 * для верхней роли нет и не появится — ни для первого администратора,
 * ни для последующих.
 *
 * Почему консоль, а не «первый вход создаёт админа»: сценарий
 * самопровозглашения нельзя закрыть — маршрут либо есть, либо нет,
 * а «есть, пока таблица пуста» держится на состоянии данных, то есть
 * на том, что кто-то не успел раньше.
 *
 * **Роль не выбирается.** Команда заводит только `SuperAdmin`.
 * Опция `--role` была бы вторым путём создания `Admin` — мимо формы,
 * мимо актора и мимо аудит-журнала, вопреки ADR-017. `Admin` заводится
 * действием `SuperAdmin`, и только им.
 *
 * **Пароль спрашивается скрытым вводом, а не берётся аргументом.**
 * Аргумент осел бы в истории командной оболочки и был бы виден
 * в списке процессов всё время выполнения — для самой привилегированной
 * учётной записи в системе это худшее из возможных мест хранения.
 *
 * Записи в аудит-журнале это событие не оставляет, и это не пропуск:
 * актор обязателен для каждой записи (CHECK на audit_record), а здесь
 * его нет — администраторов ещё не существует. След события —
 * сама строка: `created_by_admin_id = null` и `created_at`, чего
 * по признаку ADR-011 достаточно для append-only сущности.
 */
#[AsCommand(
    name: 'app:identity:create-super-admin',
    description: 'Заводит SuperAdmin — единственный путь к верхней роли',
)]
final class CreateSuperAdminCommand extends Command
{
    public function __construct(
        private readonly AdministratorRepository $administrators,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email администратора');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');

        $plainPassword = $io->askHidden('Пароль (ввод скрыт)');
        if (!\is_string($plainPassword) || '' === trim($plainPassword)) {
            $io->error('Пароль пуст.');

            return Command::FAILURE;
        }

        // Хэш считается здесь: сущность хранит готовый хэш. Хэшеру нужен
        // объект только для выбора алгоритма по конфигурации —
        // вспомогательный экземпляр никуда не сохраняется.
        $passwordHash = $this->passwordHasher->hashPassword(
            Administrator::create($email, '', AdminRole::SuperAdmin, null),
            $plainPassword,
        );

        $administrator = Administrator::create($email, $passwordHash, AdminRole::SuperAdmin, null);

        try {
            $this->administrators->add($administrator);
        } catch (UniqueConstraintViolationException) {
            // Перехват конфликта на вставке, не проверка перед ней
            // (CLAUDE.md §4). Пароль повторный запуск не меняет: смены
            // пароля в системе нет вовсе.
            $io->note(\sprintf('Администратор "%s" уже существует — повторный запуск ничего не изменил.', $email));

            return Command::SUCCESS;
        }

        $io->success(\sprintf('SuperAdmin "%s" создан. adminId=%s', $administrator->email(), $administrator->id()));

        return Command::SUCCESS;
    }
}
