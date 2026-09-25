<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Command;

use App\Ingestion\Application\Buyout\BacktestBuyoutForecastAction;
use App\Ingestion\Application\Buyout\BuyoutBacktestBucket;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Ручная проверка прогноза выкупа на прошлых датах одной компании
 * (ADR-030). Выводит только сводку ошибок, не строки заказов.
 */
#[AsCommand(
    name: 'app:ingestion:buyout-forecast-backtest',
    description: 'Сравнивает прогноз выкупа на прошлые даты с итоговым фактом (ADR-030)',
)]
final class BacktestBuyoutForecastCommand extends Command
{
    public function __construct(private readonly BacktestBuyoutForecastAction $backtest)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('companyId', InputArgument::REQUIRED)
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Первая дата прогноза, Y-m-d; по умолчанию — самая ранняя допустимая')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Последняя дата прогноза, Y-m-d; по умолчанию — вчера');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $companyId */
        $companyId = $input->getArgument('companyId');
        if (!Uuid::isValid($companyId)) {
            $io->error('companyId должен быть UUID.');

            return Command::INVALID;
        }

        try {
            $from = self::date($input->getOption('from'));
            $to = self::date($input->getOption('to'));
            $report = ($this->backtest)($companyId, $from, $to, new \DateTimeImmutable());
        } catch (\InvalidArgumentException|\DomainException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->title('Проверка прогноза выкупа на прошлых датах');
        $io->text(\sprintf(
            'Даты прогноза %s — %s (12:00 МСК), самая ранняя допустимая %s. Пар «день заказа × дата прогноза»: %d.',
            $report->fromAsOfDate->format('Y-m-d'),
            $report->toAsOfDate->format('Y-m-d'),
            $report->earliestAsOfDate->format('Y-m-d'),
            \count($report->pairs),
        ));
        $io->table(
            ['Горизонт, дн.', 'Пар', 'Без прогноза', 'Прогноз: ошибка', 'Прогноз: смещение', 'Сравнимых пар', 'На них прогноз: ошибка', 'На них наивная: ошибка', 'На них наивная: смещение'],
            array_map(static fn (BuyoutBacktestBucket $bucket): array => [
                $bucket->label,
                $bucket->cohorts,
                $bucket->cohorts - $bucket->forecastCount,
                self::points($bucket->forecastMaeBps),
                self::points($bucket->forecastBiasBps),
                $bucket->comparableCount,
                self::points($bucket->comparableForecastMaeBps),
                self::points($bucket->comparableNaiveMaeBps),
                self::points($bucket->comparableNaiveBiasBps),
            ], $report->buckets),
        );
        $io->text([
            'Ошибка — средняя абсолютная разница с итоговым фактом дня, п.п.; смещение со знаком «+» — оценка выше факта.',
            'Наивная — выкуп только по известным на дату исходам. Её нет, пока на дату ничего не закрыто, поэтому с прогнозом она сравнивается только на сравнимых парах — где есть обе оценки.',
        ]);

        return Command::SUCCESS;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
        $date = \is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Дата должна быть в формате Y-m-d.');
        }

        return $date;
    }

    private static function points(?int $basisPoints): string
    {
        return null === $basisPoints ? '—' : number_format($basisPoints / 100, 1, ',', '');
    }
}
