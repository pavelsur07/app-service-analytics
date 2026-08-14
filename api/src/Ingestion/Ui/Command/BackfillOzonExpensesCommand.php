<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Command;

use App\Ingestion\Application\Message\FetchOzonExpensesMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Разовый добор расходов за прошедшие дни.
 *
 * Планировщик тянет расходы узким окном последних дней
 * (DispatchActiveOzonSyncsAction) и только вперёд, поэтому история
 * до включения коннектора не появится сама никогда. У первого клиента
 * это выглядело так: продажи с 2 августа, расходы с 12-го — и маржа
 * за десять дней из тринадцати была выручкой минус одна комиссия.
 *
 * По одному сообщению на день — тот же приём, что у планировщика:
 * метод площадки принимает ровно одну дату (ADR-012), и упавший день
 * повторяется отдельно, не утягивая за собой остальные.
 *
 * Идемпотентность обеспечена ниже по течению и здесь не повторяется:
 * raw дедуплицируется по естественному ключу (ADR-006), расходы идут
 * апсертом. Повторный добор того же периода не удваивает ни строки.
 *
 * Подключение задаётся явно, а не берётся обходом активных: обход —
 * межарендаторное чтение, ради которого планировщик и вынесен в узкий
 * слой Deptrac (CLAUDE.md §1). Добору чужие компании не нужны, и слой
 * из-за него не расширяется.
 */
#[AsCommand(
    name: 'app:ingestion:backfill-ozon-expenses',
    description: 'Ставит в очередь загрузку расходов Ozon за каждый день диапазона',
)]
final class BackfillOzonExpensesCommand extends Command
{
    /**
     * Потолок диапазона. День — это запрос к площадке, и опечатка в годе
     * («2020-08-02» вместо «2026-08-02») означала бы две тысячи запросов
     * подряд. Отказ, а не тихая обрезка до потолка: оператор, попросивший
     * год, должен узнать, что год не поставлен.
     */
    private const int MAX_DAYS = 180;

    public function __construct(private readonly MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('companyId', InputArgument::REQUIRED)
            ->addArgument('marketplaceAccountId', InputArgument::REQUIRED)
            ->addArgument('from', InputArgument::REQUIRED, 'Первый день начислений, Y-m-d')
            ->addArgument('to', InputArgument::REQUIRED, 'Последний день начислений включительно, Y-m-d');
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
        if (null === $from) {
            $io->error("from должен быть в формате Y-m-d, получено: {$rawFrom}");

            return Command::FAILURE;
        }

        $to = self::parseDay($rawTo);
        if (null === $to) {
            $io->error("to должен быть в формате Y-m-d, получено: {$rawTo}");

            return Command::FAILURE;
        }

        if ($to < $from) {
            $io->error("Диапазон задом наперёд: from {$rawFrom} позже to {$rawTo}.");

            return Command::FAILURE;
        }

        $days = $from->diff($to)->days + 1;
        if ($days > self::MAX_DAYS) {
            $io->error(\sprintf('Диапазон в %d дней больше потолка %d — это %d запросов к площадке. Разбейте на части.', $days, self::MAX_DAYS, $days));

            return Command::FAILURE;
        }

        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $this->bus->dispatch(new FetchOzonExpensesMessage(
                companyId: $companyId,
                marketplaceAccountId: $marketplaceAccountId,
                accrualDate: $day->format('Y-m-d'),
            ));
        }

        $io->success(\sprintf('Поставлено %d дней (%s … %s) для подключения %s.', $days, $rawFrom, $rawTo, $marketplaceAccountId));

        return Command::SUCCESS;
    }

    /**
     * «!» в формате обнуляет время: без него createFromFormat подставляет
     * текущее, и сравнение границ диапазона зависело бы от того, сколько
     * микросекунд прошло между разбором from и to.
     */
    private static function parseDay(string $value): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        // Вторая проверка ловит существующие по форме, но несуществующие
        // в календаре даты: 2026-02-30 разбирается и молча переезжает
        // на 2 марта.
        if (false === $parsed || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $parsed;
    }
}
