<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Command;

use App\Ingestion\Application\Message\FetchOzonReturnsMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Ручной backfill returns: до 365 дней, последовательными окнами ≤90.
 */
#[AsCommand(
    name: 'app:ingestion:backfill-ozon-returns',
    description: 'Ставит в очередь backfill возвратов Ozon окнами не более 90 дней',
)]
final class BackfillOzonReturnsCommand extends Command
{
    private const int MAX_RANGE_DAYS = 365;
    private const int WINDOW_DAYS = 90;

    public function __construct(private readonly MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('companyId', InputArgument::REQUIRED)
            ->addArgument('marketplaceAccountId', InputArgument::REQUIRED)
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Первый день visual status change, Y-m-d')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Последний день visual status change включительно, Y-m-d');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyId = $input->getArgument('companyId');
        $accountId = $input->getArgument('marketplaceAccountId');
        $fromValue = $input->getOption('from');
        $toValue = $input->getOption('to');
        if (!\is_string($companyId) || !\is_string($accountId) || !\is_string($fromValue) || !\is_string($toValue)) {
            $io->error('companyId, marketplaceAccountId, --from и --to обязательны.');

            return Command::FAILURE;
        }

        $from = self::parseDay($fromValue);
        $to = self::parseDay($toValue);
        if (null === $from || null === $to || $to < $from) {
            $io->error('Период должен состоять из существующих дат Y-m-d; from не позже to.');

            return Command::FAILURE;
        }

        $days = $from->diff($to)->days + 1;
        if ($days > self::MAX_RANGE_DAYS) {
            $io->error(\sprintf('Диапазон в %d дней больше потолка %d.', $days, self::MAX_RANGE_DAYS));

            return Command::FAILURE;
        }

        $windows = 0;
        for ($windowFrom = $from; $windowFrom <= $to; $windowFrom = $windowTo->modify('+1 day')) {
            $candidateTo = $windowFrom->modify('+'.(self::WINDOW_DAYS - 1).' days');
            $windowTo = $candidateTo < $to ? $candidateTo : $to;
            $this->bus->dispatch(new FetchOzonReturnsMessage(
                companyId: $companyId,
                marketplaceAccountId: $accountId,
                from: $windowFrom->format('Y-m-d'),
                to: $windowTo->format('Y-m-d'),
            ));
            ++$windows;
        }

        $io->success(\sprintf('Поставлено окон: %d; дней: %d.', $windows, $days));

        return Command::SUCCESS;
    }

    private static function parseDay(string $value): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Moscow'));
        if (false === $parsed || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $parsed;
    }
}
