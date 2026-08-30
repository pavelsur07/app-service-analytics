<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Command;

use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;
use App\Ingestion\Domain\OzonReturnsListParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/** Импорт сохранённой полной страницы /v1/returns/list без HTTP, для e2e. */
#[AsCommand(
    name: 'app:ingestion:import-ozon-returns-fixture',
    description: 'Разбирает полный сохранённый ответ /v1/returns/list, без обращения к Ozon',
)]
final class ImportOzonReturnsFixtureCommand extends Command
{
    public function __construct(
        private readonly MarketplaceRawDocumentRepository $rawDocuments,
        private readonly OzonReturnsListParser $parser,
        private readonly MarketplaceReturnFactRepository $returns,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('companyId', InputArgument::REQUIRED)
            ->addArgument('marketplaceAccountId', InputArgument::REQUIRED)
            ->addArgument('businessDate', InputArgument::REQUIRED, 'Бизнес-дата периода, Y-m-d')
            ->addArgument('fixturePath', InputArgument::REQUIRED, 'Путь к полному JSON-ответу Ozon');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyId = Uuid::fromString(self::argument($input, 'companyId'));
        $accountId = Uuid::fromString(self::argument($input, 'marketplaceAccountId'));
        $businessDate = self::argument($input, 'businessDate');
        $fixturePath = self::argument($input, 'fixturePath');
        $rawBody = file_get_contents($fixturePath);
        if (false === $rawBody) {
            $io->error("Не удалось прочитать файл: {$fixturePath}");

            return Command::FAILURE;
        }

        $rawDocumentId = $this->rawDocuments->add(MarketplaceRawDocument::capture(
            companyId: $companyId,
            marketplaceAccountId: $accountId,
            reportType: MarketplaceReportType::OzonReturnsList,
            period: new \DateTimeImmutable($businessDate),
            rawBody: $rawBody,
        ));
        $page = $this->parser->parse($rawBody, $companyId, $accountId, $rawDocumentId, 0);
        if ($page->hasNext) {
            throw new \UnexpectedValueException('Returns fixture must contain the complete final page (has_next=false).');
        }
        $this->returns->upsertAll($page->facts);

        $io->success(\sprintf('Импортировано %d строк возвратов из %s.', \count($page->facts), $fixturePath));

        return Command::SUCCESS;
    }

    private static function argument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);
        if (!\is_string($value)) {
            throw new \InvalidArgumentException("Argument {$name} must be a string.");
        }

        return $value;
    }
}
