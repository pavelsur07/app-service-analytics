<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Command;

use App\Ingestion\Domain\DayLoadMark;
use App\Ingestion\Domain\MarketplaceDayLoadRepository;
use App\Ingestion\Domain\MarketplaceExpenseFactRepository;
use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\OzonAccrualByDayParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/** Только полная синтетическая выгрузка дня, без HTTP к площадке. */
#[When(env: 'dev')]
#[When(env: 'test')]
#[AsCommand(name: 'app:ingestion:import-ozon-accrual-fixture', description: 'Импорт полной локальной страницы начислений для dev/test')]
final class ImportOzonAccrualFixtureCommand extends Command
{
    public function __construct(
        private readonly MarketplaceRawDocumentRepository $rawDocuments,
        private readonly OzonAccrualByDayParser $parser,
        private readonly MarketplaceExpenseFactRepository $expenses,
        private readonly MarketplaceDayLoadRepository $dayLoads,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        foreach (['companyId', 'marketplaceAccountId', 'businessDate', 'fixturePath'] as $argument) {
            $this->addArgument($argument, InputArgument::REQUIRED);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId = Uuid::fromString(self::argument($input, 'companyId'));
        $accountId = Uuid::fromString(self::argument($input, 'marketplaceAccountId'));
        $date = self::argument($input, 'businessDate');
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $day || $day->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('businessDate must be a calendar date Y-m-d.');
        }

        try {
            $body = file_get_contents(self::argument($input, 'fixturePath'));
            if (false === $body) {
                throw new \UnexpectedValueException('Cannot read accrual fixture.');
            }
            $rawId = $this->rawDocuments->add(MarketplaceRawDocument::capture($companyId, $accountId, MarketplaceReportType::OzonAccrualByDay, $day, $body));
            $page = $this->parser->parse($body, $companyId, $accountId, $rawId);
            if ('' !== $page['lastId']) {
                throw new \UnexpectedValueException('Accrual fixture must contain the complete day (last_id empty).');
            }
            foreach ($page['facts'] as $fact) {
                if ($fact->businessDate()->format('Y-m-d') !== $date) {
                    throw new \UnexpectedValueException('Accrual business date does not match fixture day.');
                }
            }
            $this->expenses->upsertAll($page['facts']);
            $this->dayLoads->mark($companyId->toRfc4122(), $accountId->toRfc4122(), [DayLoadMark::completed(MarketplaceReportType::OzonAccrualByDay, $day, $day)], new \DateTimeImmutable());
        } catch (\Throwable $failure) {
            $this->dayLoads->mark($companyId->toRfc4122(), $accountId->toRfc4122(), [DayLoadMark::failed(MarketplaceReportType::OzonAccrualByDay, $day, $day, $failure->getMessage())], new \DateTimeImmutable());

            throw $failure;
        }
        $output->writeln(\sprintf('Imported %d accrual facts for %s.', \count($page['facts']), $date));

        return Command::SUCCESS;
    }

    private static function argument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('Expected a string argument: '.$name);
        }

        return $value;
    }
}
