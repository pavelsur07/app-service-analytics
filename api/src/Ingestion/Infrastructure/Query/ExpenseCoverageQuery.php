<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Ingestion\Domain\MarketplaceReportType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Сколько дней окна показывают маржу, посчитанную без расходов.
 *
 * Юнит-экономика складывает продажи и расходы за один период, и день,
 * по которому расходы не загружены, выглядит на экране обычным: выручка
 * есть, комиссия есть, маржа получается завышенной ровно на логистику,
 * возвраты и обработку. Отличить его от дня, когда расходов правда
 * не было, по самим данным невозможно — отсюда этот запрос.
 *
 * **Меряется загрузка, а не факты** (CLAUDE.md, «Наблюдаемость»). День
 * без единого начисления — законный случай: у продавца бывает день без
 * заказов. День, за который выгрузка не проходила, — не законный, и по
 * фактам эти два неразличимы. Признак загрузки — raw-документ: period
 * входит в его естественный ключ (ADR-006), поэтому строка появляется
 * за каждый обработанный день, даже когда ответ площадки пуст.
 *
 * Считаются только дни, за которые загружены продажи. Иначе окно
 * в 90 дней у клиента, подключившегося неделю назад, объявляло бы
 * несчитанными восемьдесят с лишним дней, которых у него никогда
 * и не было, — тревога, которая всегда горит, перестаёт читаться.
 *
 * Группировка по подключению, а не только по дню: у компании их бывает
 * несколько, и загруженный день одного кабинета не означает загруженный
 * день другого.
 */
final readonly class ExpenseCoverageQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function daysWithoutExpenses(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): QueryBuilder {
        // Отдельный индекс не заводится: запрос идёт по префиксу
        // company_id уникального ключа raw-слоя, и документов у компании
        // сегодня две сотни, за год будет несколько тысяч. Окно ограничено
        // сверху потолком days у контроллера, так что и объём чтения
        // ограничен. Свой индекс окупится, когда документов станет
        // сотни тысяч, — тогда (company_id, report_type, period).
        $uncovered = <<<SQL
            (
                SELECT period
                FROM marketplace_raw_document
                WHERE company_id = :companyId
                  AND period >= :from
                  AND period <= :to
                  AND report_type IN (:salesReportType, :expensesReportType)
                GROUP BY marketplace_account_id, period
                HAVING bool_or(report_type = :salesReportType)
                   AND NOT bool_or(report_type = :expensesReportType)
            ) AS uncovered
            SQL;

        return $this->connection->createQueryBuilder()
            ->select('COUNT(DISTINCT period) AS days_without_expenses')
            ->from($uncovered)
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('salesReportType', MarketplaceReportType::OzonPostingFboList)
            ->setParameter('expensesReportType', MarketplaceReportType::OzonAccrualByDay);
    }
}
