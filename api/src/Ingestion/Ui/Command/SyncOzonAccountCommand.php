<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Command;

use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Ручной разовый запуск синхронизации одного бизнес-дня одного
 * Ozon-подключения — расписания пока нет (ADR-006, FetchOzonPostingsMessage:
 * «за пределами tracer bullet»). Существование companyId/marketplaceAccountId
 * здесь не проверяется — это уже делает FetchOzonPostingsHandler.
 */
#[AsCommand(
    name: 'app:ingestion:sync-ozon-account',
    description: 'Ставит в очередь синхронизацию одного бизнес-дня Ozon-подключения',
)]
final class SyncOzonAccountCommand extends Command
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('companyId', InputArgument::REQUIRED)
            ->addArgument('marketplaceAccountId', InputArgument::REQUIRED)
            ->addArgument('businessDate', InputArgument::REQUIRED, 'Бизнес-дата периода, Y-m-d');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $companyId */
        $companyId = $input->getArgument('companyId');
        /** @var string $marketplaceAccountId */
        $marketplaceAccountId = $input->getArgument('marketplaceAccountId');
        /** @var string $businessDate */
        $businessDate = $input->getArgument('businessDate');

        $parsedDate = \DateTimeImmutable::createFromFormat('Y-m-d', $businessDate);
        if (false === $parsedDate || $parsedDate->format('Y-m-d') !== $businessDate) {
            $io->error("businessDate должен быть в формате Y-m-d, получено: {$businessDate}");

            return Command::FAILURE;
        }

        $this->bus->dispatch(new FetchOzonPostingsMessage(
            companyId: $companyId,
            marketplaceAccountId: $marketplaceAccountId,
            businessDate: $businessDate,
        ));

        $io->success("Синхронизация за {$businessDate} поставлена в очередь для подключения {$marketplaceAccountId}.");

        return Command::SUCCESS;
    }
}
