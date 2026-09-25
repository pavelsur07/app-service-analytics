<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Command;

use App\Ingestion\Application\IngestionBackfill;
use App\Ingestion\Application\Message\FetchOzonAdCampaignStatsMessage;
use App\Ingestion\Application\OzonAdvertisingWindows;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Разовый повтор загрузки рекламы (ADR-026 п. 4): расход и статистика
 * кампаний кусками по 30 дней с SKU-отчётами — для явно заданного
 * подключения, как `app:ingestion:backfill-ozon-expenses`. Подключение
 * передаётся явно: межарендаторного поиска здесь нет, поэтому команда
 * живёт в обычном слое `IngestionUi`. Реклама подключения неактивна —
 * обработчики пропустят куски сами.
 */
#[AsCommand(
    name: 'app:ingestion:backfill-ozon-advertising',
    description: 'Ставит в очередь загрузку рекламы Ozon (расход, статистика, SKU-отчёты) за период',
)]
final class BackfillOzonAdvertisingCommand extends Command
{
    /** Первичная загрузка — год; потолок с запасом на високосный. */
    private const int MAX_DAYS = 366;

    public function __construct(private readonly MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('companyId', InputArgument::REQUIRED)
            ->addArgument('marketplaceAccountId', InputArgument::REQUIRED)
            ->addArgument('from', InputArgument::REQUIRED, 'Первый день, Y-m-d')
            ->addArgument('to', InputArgument::REQUIRED, 'Последний день включительно, Y-m-d');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $companyId */
        $companyId = $input->getArgument('companyId');
        /** @var string $marketplaceAccountId */
        $marketplaceAccountId = $input->getArgument('marketplaceAccountId');
        /** @var string $rawFrom */
        $rawFrom = $input->getArgument('from');
        /** @var string $rawTo */
        $rawTo = $input->getArgument('to');

        $from = self::parseDay($rawFrom);
        $to = self::parseDay($rawTo);
        if (null === $from || null === $to) {
            $io->error("from и to должны быть в формате Y-m-d, получено: {$rawFrom}, {$rawTo}");

            return Command::FAILURE;
        }

        if ($to < $from) {
            $io->error("Диапазон задом наперёд: from {$rawFrom} позже to {$rawTo}.");

            return Command::FAILURE;
        }

        $days = $from->diff($to)->days + 1;
        if ($days > self::MAX_DAYS) {
            $io->error(\sprintf('Диапазон в %d дней больше потолка %d. Разбейте на части.', $days, self::MAX_DAYS));

            return Command::FAILURE;
        }

        $chunks = OzonAdvertisingWindows::between($from, $to);
        foreach ($chunks as $chunk) {
            $this->bus->dispatch(new FetchOzonAdCampaignStatsMessage($companyId, $marketplaceAccountId, $chunk['from'], $chunk['to'], withReports: true), IngestionBackfill::stamps());
        }

        $io->success(\sprintf('Поставлено %d кусков (%s … %s) для подключения %s.', \count($chunks), $rawFrom, $rawTo, $marketplaceAccountId));

        return Command::SUCCESS;
    }

    /**
     * «!» обнуляет время; вторая проверка ловит несуществующие даты
     * (2026-02-30 молча переехала бы на 2 марта).
     */
    private static function parseDay(string $value): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone(OzonAdvertisingWindows::TIMEZONE));
        if (false === $parsed || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $parsed;
    }
}
