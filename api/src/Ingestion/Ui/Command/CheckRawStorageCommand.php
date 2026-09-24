<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Command;

use App\Ingestion\Infrastructure\Storage\S3RawStorageHealthCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Проверка хранилища сырья (ADR-024): на проде — после заведения бакета
 * и ключей и при подозрении на сбой (docs/operations-checklist.md);
 * в песочнице с --create-bucket — подготовка бакета (make s3-bucket-create).
 */
#[AsCommand(
    name: 'app:ingestion:raw-storage-check',
    description: 'Запись, чтение и удаление пробного объекта в хранилище сырья',
)]
final class CheckRawStorageCommand extends Command
{
    public function __construct(
        private readonly S3RawStorageHealthCheck $healthCheck,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('create-bucket', null, InputOption::VALUE_NONE, 'Создать бакет, если его нет — только dev и test');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ((bool) $input->getOption('create-bucket')) {
            // Бакет создаётся из приложения только в песочнице; везде,
            // где это не dev и не test, его заводит владелец в панели
            // провайдера. Список разрешённых, а не запрещённых окружений.
            if (!\in_array($this->environment, ['dev', 'test'], true)) {
                $io->error(\sprintf('--create-bucket доступен только в dev и test, не в %s: бакет создаётся в панели провайдера.', $this->environment));

                return Command::FAILURE;
            }

            $io->writeln($this->healthCheck->ensureBucket()
                ? \sprintf('Бакет %s создан.', $this->healthCheck->bucket())
                : \sprintf('Бакет %s уже есть.', $this->healthCheck->bucket()));
        }

        $probe = $this->healthCheck->probe();
        $io->success(\sprintf('Хранилище сырья работает: бакет %s, пробный объект %s записан, прочитан и удалён.', $this->healthCheck->bucket(), $probe['key']));
        if (!$probe['conditionalWrite']) {
            // Не отказ: запись и чтение работают. Но повторная загрузка
            // будет перезаписывать объект, и в версионируемом бакете это
            // копит версии — до этапа 2 нужен lifecycle неактуальных версий.
            $io->warning('Провайдер не соблюдает условную запись (If-None-Match: *): повторная запись перезаписывает объект. Нужно правило lifecycle для неактуальных версий (ADR-024).');
        } else {
            $io->writeln('Условная запись соблюдается: повтор не перезаписывает объект.');
        }

        return Command::SUCCESS;
    }
}
