<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Command;

use App\Ingestion\Application\StoreOzonCampaignCatalog;
use App\Ingestion\Infrastructure\Persistence\RawDocumentBody;
use App\Ingestion\Infrastructure\Query\OzonCampaignCatalogSourceQuery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

/** Scoped rebuild from immutable raw; no marketplace client or credentials. */
#[AsCommand(name: 'app:ingestion:rebuild-ozon-campaign-catalog', description: 'Строит каталог кампаний из сохранённого ответа без обращения к Ozon')]
final class RebuildOzonCampaignCatalogCommand extends Command
{
    public function __construct(private readonly OzonCampaignCatalogSourceQuery $source, private readonly RawDocumentBody $body, private readonly StoreOzonCampaignCatalog $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('companyId', InputArgument::REQUIRED)->addArgument('accountId', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $company = $input->getArgument('companyId');
        $account = $input->getArgument('accountId');
        if (!\is_string($company) || !Uuid::isValid($company) || !\is_string($account) || !Uuid::isValid($account)) {
            $output->writeln('<error>Укажите UUID компании и подключения.</error>');

            return Command::INVALID;
        }
        $company = Uuid::fromString($company)->toRfc4122();
        $account = Uuid::fromString($account)->toRfc4122();
        $row = $this->source->latest($company, $account)->executeQuery()->fetchAssociative();
        if (false === $row) {
            $output->writeln('<error>Сохранённого списка кампаний этого подключения нет.</error>');

            return Command::FAILURE;
        }
        $id = $row['id'];
        $received = $row['received_at'];
        if (!\is_string($id) || !\is_string($received)) {
            throw new \UnexpectedValueException('Invalid raw campaign metadata.');
        }
        $ok = $this->store->store($company, Uuid::fromString($account), Uuid::fromString($id), new \DateTimeImmutable($received), $this->body->read($company, $row), restored: true);
        $output->writeln($ok ? '<info>Сохранённый список обработан. Новых запросов Ozon нет.</info>' : '<error>Сохранённый список не прошёл проверку; сырьё не изменено.</error>');

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
