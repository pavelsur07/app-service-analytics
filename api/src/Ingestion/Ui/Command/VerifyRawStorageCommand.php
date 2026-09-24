<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Command;

use App\Ingestion\Domain\RawDocumentStorage;
use App\Ingestion\Domain\RawObjectNotFound;
use App\Ingestion\Infrastructure\Persistence\RawDocumentBody;
use App\Ingestion\Infrastructure\Query\AllCompaniesRawObjectRow;
use App\Ingestion\Infrastructure\Query\AllCompaniesRawObjectsSinceQuery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Сверка хранилища сырья на время наблюдения этапа 2 (ADR-024,
 * docs/operations-checklist.md): у каждой строки с ключом объект есть,
 * ключ совпадает с пересобранным из полей строки, sha256 тела — с body_hash,
 * размер — с byte_size. --since сужает проверку до полученных после него
 * строк и считает среди них строки без объекта; без --since проверяются
 * все строки с ключом, включая старые, которым ключ дописан при повторной
 * загрузке (их received_at прежний).
 *
 * Межарендаторное чтение — операционная задача (CLAUDE.md §1); команда
 * в узком слое Deptrac IngestionRawVerificationCommand. Содержимое тел
 * не печатается — только id расходящихся строк.
 */
#[AsCommand(
    name: 'app:ingestion:raw-storage-verify',
    description: 'Сверка объектов сырья в S3 со строками marketplace_raw_document',
)]
final class VerifyRawStorageCommand extends Command
{
    /** Потолок перечисляемых расхождений: итог считается целиком, список — нет. */
    private const int LISTED_PROBLEMS = 50;

    public function __construct(
        private readonly AllCompaniesRawObjectsSinceQuery $rawObjects,
        private readonly RawDocumentStorage $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('since', null, InputOption::VALUE_REQUIRED, 'Только строки, полученные после, например "2026-09-25 10:00"; без него — все строки с ключом');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sinceOption = $input->getOption('since');
        $since = \is_string($sinceOption) && '' !== $sinceOption ? new \DateTimeImmutable($sinceOption) : null;

        $checked = 0;
        $bytes = 0;
        $problems = [];
        $problemCount = 0;
        $cursorReceivedAt = null;
        $cursorId = null;

        do {
            $rows = $this->rawObjects->build($since, $cursorReceivedAt, $cursorId)->executeQuery()->fetchAllAssociative();
            foreach ($rows as $rawRow) {
                $row = AllCompaniesRawObjectsSinceQuery::mapRow($rawRow);
                ++$checked;
                $bytes += $row->byteSize;
                $problem = $this->check($row);
                if (null !== $problem) {
                    ++$problemCount;
                    if (\count($problems) < self::LISTED_PROBLEMS) {
                        $problems[] = $problem;
                    }
                }
                $cursorReceivedAt = $row->receivedAt;
                $cursorId = $row->id;
            }
        } while (AllCompaniesRawObjectsSinceQuery::PAGE === \count($rows));

        // Без --since строки без объекта — это все документы до этапа 2:
        // их число ничего не говорит о работе хранилища и не считается.
        $withoutObject = null === $since ? 0 : $this->rawObjects->countWithoutObjectSince($since);

        $io->writeln([
            \sprintf('%s: строк с объектом — %d, исходных байт — %d.', null === $since ? 'Все строки' : 'С '.$since->format('Y-m-d H:i:s'), $checked, $bytes),
            null === $since ? 'Строки без объекта не считаются: без --since это документы до этапа 2.' : \sprintf('Строк без объекта (тело в базе) — %d.', $withoutObject),
            \sprintf('Расхождений — %d.', $problemCount),
        ]);
        foreach ($problems as $problem) {
            $io->writeln('  - '.$problem);
        }

        if ($problemCount > 0) {
            $io->error('Хранилище расходится со строками.');

            return Command::FAILURE;
        }
        // При RAW_BODY_STORE=s3 новых строк без объекта быть не должно:
        // значит, тело ушло в базу — аварийный режим или код в обход
        // хранилища. Это не порча данных, но и не «всё в порядке».
        if ($withoutObject > 0) {
            $io->error('Есть строки без объекта: тело записано в базу. Проверьте RAW_BODY_STORE.');

            return Command::FAILURE;
        }
        $io->success('Все объекты на месте и совпадают с body_hash.');

        return Command::SUCCESS;
    }

    private function check(AllCompaniesRawObjectRow $row): ?string
    {
        $id = $row->id->toRfc4122();
        $key = RawDocumentBody::key(Uuid::fromString($row->companyId), $row->marketplaceAccountId, $row->reportType, $row->period, $row->bodyHash);
        if ($key->toString() !== $row->storageKey) {
            return \sprintf('%s: ключ в строке не совпадает с пересобранным', $id);
        }

        try {
            $body = $this->storage->get($row->companyId, $key);
        } catch (RawObjectNotFound) {
            return \sprintf('%s: объекта нет', $id);
        }

        if (hash('sha256', $body) !== $row->bodyHash) {
            return \sprintf('%s: sha256 тела не совпадает с body_hash', $id);
        }

        return \strlen($body) === $row->byteSize ? null : \sprintf('%s: размер тела не совпадает с byte_size', $id);
    }
}
