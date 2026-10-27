<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Command;

use App\Ingestion\Domain\MarketplaceListing;
use App\Ingestion\Domain\MarketplaceListingRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/** Синтетический каталог тестового подключения: реальные цены вводит E2E через UI. */
#[When(env: 'dev')]
#[When(env: 'test')]
#[AsCommand(name: 'app:ingestion:seed-dashboard-sandbox-catalog', description: 'Одна синтетическая карточка для E2E dashboard (dev/test)')]
final class SeedDashboardSandboxCatalogCommand extends Command
{
    public function __construct(private readonly MarketplaceListingRepository $listings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('companyId', InputArgument::REQUIRED)->addArgument('marketplaceAccountId', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $company = $input->getArgument('companyId');
        $account = $input->getArgument('marketplaceAccountId');
        if (!\is_string($company) || !\is_string($account)) {
            throw new \InvalidArgumentException('Company and account must be UUID strings.');
        }
        $companyId = Uuid::fromString($company);
        $accountId = Uuid::fromString($account);
        $this->listings->replaceForAccount($company, $accountId, [MarketplaceListing::seen($companyId, $accountId, '900000001', 'E2E-PROFIT', 'E2E Dashboard Profit', null, new \DateTimeImmutable())]);
        $output->writeln('Seeded E2E Dashboard Profit.');

        return Command::SUCCESS;
    }
}
