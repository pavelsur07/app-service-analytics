<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Command;

use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\OzonPostingFboListParser;
use App\Ingestion\Domain\OzonPostingStatusParser;
use App\Ingestion\Domain\SalesFactRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Разбирает уже полученный ответ /v2/posting/fbo/list с диска — без HTTP
 * (ADR-006: «разбор можно переиграть на сохранённом сырье без обращения
 * к API»). Ручное тестирование и e2e: реальных ключей Ozon в песочнице
 * нет (CLAUDE.md, «Периметр автономной работы»), поэтому синхронизация
 * через FetchOzonPostingsHandler в этих сценариях недоступна — тот же
 * путь (raw -> парсер -> upsert facts), но источник сырья — файл,
 * не OzonPostingsFetcher.
 */
#[AsCommand(
    name: 'app:ingestion:import-ozon-fixture',
    description: 'Разбирает сохранённый ответ /v2/posting/fbo/list в sales_fact, без обращения к Ozon',
)]
final class ImportOzonFixtureCommand extends Command
{
    private const string REPORT_TYPE = 'ozon_posting_fbo_list';

    public function __construct(
        private readonly MarketplaceRawDocumentRepository $rawDocuments,
        private readonly SalesFactRepository $salesFacts,
        private readonly OzonPostingFboListParser $parser,
        private readonly OzonPostingStatusParser $statusParser,
        private readonly MarketplacePostingStatusRepository $postingStatuses,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('companyId', InputArgument::REQUIRED)
            ->addArgument('marketplaceAccountId', InputArgument::REQUIRED)
            ->addArgument('businessDate', InputArgument::REQUIRED, 'Бизнес-дата периода, Y-m-d')
            ->addArgument('fixturePath', InputArgument::REQUIRED, 'Путь к JSON-файлу с ответом Ozon');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $companyIdArgument */
        $companyIdArgument = $input->getArgument('companyId');
        /** @var string $marketplaceAccountIdArgument */
        $marketplaceAccountIdArgument = $input->getArgument('marketplaceAccountId');
        /** @var string $businessDate */
        $businessDate = $input->getArgument('businessDate');
        /** @var string $fixturePath */
        $fixturePath = $input->getArgument('fixturePath');

        $rawBody = file_get_contents($fixturePath);
        if (false === $rawBody) {
            $io->error("Не удалось прочитать файл: {$fixturePath}");

            return Command::FAILURE;
        }

        $companyId = Uuid::fromString($companyIdArgument);
        $marketplaceAccountId = Uuid::fromString($marketplaceAccountIdArgument);
        $period = new \DateTimeImmutable($businessDate);

        $observedAt = new \DateTimeImmutable();
        $rawDocument = MarketplaceRawDocument::capture(
            companyId: $companyId,
            marketplaceAccountId: $marketplaceAccountId,
            reportType: self::REPORT_TYPE,
            period: $period,
            rawBody: $rawBody,
            receivedAt: $observedAt,
        );
        $rawDocumentId = $this->rawDocuments->add($rawDocument);

        $statuses = $this->statusParser->parse(
            $rawBody,
            $companyId,
            $marketplaceAccountId,
            $rawDocumentId,
            $observedAt,
        );
        $facts = $this->parser->parse($rawBody, $companyId, $marketplaceAccountId, $rawDocumentId);
        $this->postingStatuses->recordChanged($companyIdArgument, $statuses);
        $this->salesFacts->upsertAll($facts);

        $io->success(\sprintf(
            'Импортировано %d наблюдений статуса и %d строк факта из %s.',
            \count($statuses),
            \count($facts),
            $fixturePath,
        ));

        return Command::SUCCESS;
    }
}
