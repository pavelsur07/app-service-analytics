<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Buyout;

use App\Ingestion\Infrastructure\Query\Buyout\BuyoutBacktestStartQuery;
use App\Ingestion\Infrastructure\Query\Buyout\BuyoutDailyQuery;
use App\Ingestion\Infrastructure\Query\Buyout\BuyoutDailyRow;
use Doctrine\DBAL\Connection;

/**
 * Проверка прогноза выкупа на прошлых датах одной компании (ADR-030).
 * Одна выборка на дату прогноза — согласованное отступление от правила
 * «запросы в цикле запрещены», число дат ограничено MAX_AS_OF_DATES.
 */
final readonly class BacktestBuyoutForecastAction
{
    public const int MAX_AS_OF_DATES = 62;
    private const string TIMEZONE = 'Europe/Moscow';
    private const string AS_OF_TIME = '12:00';

    public function __construct(
        private Connection $connection,
        private BuyoutDailyQuery $daily,
        private BuyoutBacktestStartQuery $start,
    ) {
    }

    /**
     * @throws \InvalidArgumentException период вне допустимых границ
     * @throws \DomainException          у компании ещё нет загруженных возвратов
     */
    public function __invoke(
        string $companyId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        \DateTimeImmutable $now,
    ): BuyoutBacktestReport {
        $read = function (Connection $connection) use ($companyId, $from, $to, $now): BuyoutBacktestReport {
            $moscow = new \DateTimeZone(self::TIMEZONE);
            $today = self::date($now->setTimezone($moscow)->format('Y-m-d'));
            $earliest = $this->earliestAsOfDate($connection, $companyId);
            if ($earliest >= $today) {
                throw new \DomainException(\sprintf('Возвраты компании загружены недавно: самая ранняя дата прогноза %s ещё не прошла.', $earliest->format('Y-m-d')));
            }
            $from = null === $from ? $earliest : self::date($from->format('Y-m-d'));
            $to = null === $to ? $today->modify('-1 day') : self::date($to->format('Y-m-d'));

            if ($from < $earliest) {
                throw new \InvalidArgumentException(\sprintf('Дата прогноза раньше %s: возвраты компании до неё не загружены (ADR-030).', $earliest->format('Y-m-d')));
            }
            if ($to < $from || $to >= $today) {
                throw new \InvalidArgumentException('Период дат прогноза должен идти от --from до --to и заканчиваться не позже вчерашнего дня.');
            }
            $dates = (int) $from->diff($to)->format('%a') + 1;
            if ($dates > self::MAX_AS_OF_DATES) {
                throw new \InvalidArgumentException(\sprintf('Не больше %d дат прогноза за запуск.', self::MAX_AS_OF_DATES));
            }

            $horizon = BuyoutBacktest::MAX_HORIZON_DAYS;
            $final = [];
            foreach ($this->rows($connection, $companyId, $from->modify("-{$horizon} days"), $to->modify('-1 day'), $now, false) as $row) {
                $final[$row->date] = $row;
            }

            $pairs = [];
            for ($asOfDate = $from; $asOfDate <= $to; $asOfDate = $asOfDate->modify('+1 day')) {
                $asOf = new \DateTimeImmutable($asOfDate->format('Y-m-d').' '.self::AS_OF_TIME, $moscow);
                $atAsOf = $this->rows($connection, $companyId, $asOfDate->modify("-{$horizon} days"), $asOfDate->modify('-1 day'), $asOf, true);
                array_push($pairs, ...BuyoutBacktest::pairs($asOfDate, $atAsOf, $final));
            }

            return new BuyoutBacktestReport(
                earliestAsOfDate: $earliest,
                fromAsOfDate: $from,
                toAsOfDate: $to,
                pairs: $pairs,
                buckets: BuyoutBacktest::summarize($pairs),
            );
        };

        $nativeConnection = $this->connection->getNativeConnection();
        if (
            $this->connection->isTransactionActive()
            || ($nativeConnection instanceof \PDO && $nativeConnection->inTransaction())
        ) {
            $this->connection->createSavepoint('buyout_backtest_guard');
            try {
                self::configurePlanner($this->connection);

                return $read($this->connection);
            } finally {
                $this->connection->rollbackSavepoint('buyout_backtest_guard');
                $this->connection->releaseSavepoint('buyout_backtest_guard');
            }
        }

        // Все даты прогноза читают один снимок: загрузка посреди запуска
        // не должна делить проверку на «до» и «после».
        return $this->connection->transactional(
            static function (Connection $connection) use ($read): BuyoutBacktestReport {
                $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                self::configurePlanner($connection);

                return $read($connection);
            },
        );
    }

    private function earliestAsOfDate(Connection $connection, string $companyId): \DateTimeImmutable
    {
        $query = $this->start->build($companyId);
        $row = $connection->fetchAssociative($query->getSQL(), $query->getParameters(), $query->getParameterTypes());
        $loadedAt = false === $row ? null : BuyoutBacktestStartQuery::mapRow($row);
        if (null === $loadedAt) {
            throw new \DomainException('У компании нет загруженных возвратов: прогноз на прошлые даты проверить не на чем.');
        }

        return self::date($loadedAt->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d'))->modify('+1 day');
    }

    /** @return list<BuyoutDailyRow> */
    private function rows(
        Connection $connection,
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $asOf,
        bool $pointInTime,
    ): array {
        $query = $this->daily->build($companyId, null, $from, $to, $asOf, $pointInTime);

        return array_map(
            BuyoutDailyQuery::mapRow(...),
            $connection->fetchAllAssociative($query->getSQL(), $query->getParameters(), $query->getParameterTypes()),
        );
    }

    /** Дата без времени в поясе по умолчанию — так же разбирается дата строки ряда. */
    private static function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    private static function configurePlanner(Connection $connection): void
    {
        $connection->executeStatement('SET LOCAL jit = off');
        $connection->executeStatement('SET LOCAL enable_nestloop = off');
        // Ручная команда: каждая дата — полный пересчёт истории компании.
        $connection->executeStatement("SET LOCAL statement_timeout = '60s'");
    }
}
