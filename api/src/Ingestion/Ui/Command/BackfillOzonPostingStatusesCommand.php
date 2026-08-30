<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Command;

use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\OzonPostingFboListParser;
use App\Ingestion\Domain\OzonPostingStatusParser;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\OzonPostingRawHistoryQuery;
use App\Ingestion\Infrastructure\Query\OzonPostingRawHistoryRow;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:ingestion:backfill-ozon-posting-statuses',
    description: 'Восстанавливает status history и sales links из сохранённых raw Ozon',
)]
final class BackfillOzonPostingStatusesCommand extends Command
{
    private const int PAGE_SIZE = 100;
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private readonly OzonPostingRawHistoryQuery $rawHistory,
        private readonly OzonPostingStatusParser $statusParser,
        private readonly OzonPostingFboListParser $salesParser,
        private readonly MarketplacePostingStatusRepository $postingStatuses,
        private readonly SalesFactRepository $salesFacts,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('companyId', InputArgument::REQUIRED)
            ->addArgument('marketplaceAccountId', InputArgument::REQUIRED)
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Первый день received_at, Y-m-d')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Последний день received_at включительно, Y-m-d')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Разобрать raw без записи status/sales facts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyIdValue = $input->getArgument('companyId');
        $accountIdValue = $input->getArgument('marketplaceAccountId');
        $fromValue = $input->getOption('from');
        $toValue = $input->getOption('to');
        if (!\is_string($companyIdValue) || !\is_string($accountIdValue)
            || !\is_string($fromValue) || !\is_string($toValue)) {
            $io->error('companyId, marketplaceAccountId, --from и --to обязательны.');

            return Command::FAILURE;
        }

        try {
            $companyId = Uuid::fromString($companyIdValue);
            $accountId = Uuid::fromString($accountIdValue);
        } catch (\InvalidArgumentException) {
            $io->error('companyId и marketplaceAccountId должны быть UUID.');

            return Command::FAILURE;
        }

        $timezone = new \DateTimeZone(self::TIMEZONE);
        $from = self::parseDay($fromValue, $timezone);
        $to = self::parseDay($toValue, $timezone);
        if (null === $from || null === $to || $to < $from) {
            $io->error('Период должен состоять из существующих дат Y-m-d; from не позже to.');

            return Command::FAILURE;
        }

        // received_at хранится как UTC timestamp without time zone; календарные
        // границы задаются в часовом поясе Ozon и переводятся перед сравнением.
        $utc = new \DateTimeZone('UTC');
        $fromUtc = $from->setTimezone($utc);
        $toExclusiveUtc = $to->modify('+1 day')->setTimezone($utc);
        $dryRun = true === $input->getOption('dry-run');
        $documents = 0;
        $parsedStatuses = 0;
        $newStatuses = 0;
        $parsedFacts = 0;
        $cursorReceivedAt = null;
        $cursorId = null;

        do {
            $rawRows = $this->rawHistory->build(
                $companyIdValue,
                $accountIdValue,
                $fromUtc,
                $toExclusiveUtc,
                $cursorReceivedAt,
                $cursorId,
                self::PAGE_SIZE,
            )->executeQuery()->fetchAllAssociative();

            foreach ($rawRows as $rawRow) {
                $row = OzonPostingRawHistoryQuery::mapRow($rawRow);
                [$statusCount, $insertedCount, $factCount] = $this->processRow(
                    $row,
                    $companyId,
                    $accountId,
                    $companyIdValue,
                    $dryRun,
                );
                ++$documents;
                $parsedStatuses += $statusCount;
                $newStatuses += $insertedCount;
                $parsedFacts += $factCount;
                $cursorReceivedAt = $row->receivedAt;
                $cursorId = $row->id;
            }
        } while (self::PAGE_SIZE === \count($rawRows));

        $io->success(\sprintf(
            'documents=%d statuses=%d new_statuses=%d facts=%d%s',
            $documents,
            $parsedStatuses,
            $newStatuses,
            $parsedFacts,
            $dryRun ? ' dry-run' : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array{int, int, int}
     */
    private function processRow(
        OzonPostingRawHistoryRow $row,
        Uuid $companyId,
        Uuid $accountId,
        string $companyIdValue,
        bool $dryRun,
    ): array {
        $statuses = $this->statusParser->parse(
            $row->body,
            $companyId,
            $accountId,
            $row->id,
            $row->receivedAt,
        );
        $facts = $this->salesParser->parse($row->body, $companyId, $accountId, $row->id);

        if ($dryRun) {
            return [\count($statuses), 0, \count($facts)];
        }

        $inserted = $this->postingStatuses->recordChanged($companyIdValue, $statuses);
        $this->salesFacts->backfillLinks($companyIdValue, $facts);

        return [\count($statuses), $inserted, \count($facts)];
    }

    private static function parseDay(string $value, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if (false === $parsed || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $parsed;
    }
}
