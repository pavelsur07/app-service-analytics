<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Dashboard;

use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Infrastructure\Query\DayLoadSql;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Загружены ли данные главной за период — по каждому подключению
 * и по обоим источникам: отправлениям и ленте начислений.
 *
 * **Меряется загрузка, а не факты** (CLAUDE.md, «Наблюдаемость»):
 * день без единого заказа — законный ноль, а день до подключения или
 * после остановки синхронизации — отсутствие данных, и по фактам их
 * не различить. Признак — raw-документ: period входит в его естественный
 * ключ (ADR-006), строка есть за каждый обработанный день, даже с пустым
 * ответом площадки. День, последний прогон которого отказал, не считается,
 * хоть raw за него и есть (ADR-038): записано могло быть не всё.
 *
 * **По подключению, а не по компании**, как у `ExpenseCoverageQuery`:
 * загруженный день одного кабинета не означает загруженный день другого.
 * Второй кабинет, подключённый посреди периода сравнения, иначе выглядел
 * бы ростом — правдоподобным и неверным.
 *
 * Без этих отметок сравнение с неполным периодом показывало бы «+∞%»
 * или «рост в сто раз».
 *
 * Отдельный индекс не нужен по той же причине, что у
 * `ExpenseCoverageQuery`: запрос идёт по префиксу `company_id`
 * уникального ключа raw-слоя, документов у компании тысячи.
 */
final readonly class DashboardLoadsQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Полон ли период: каждое подключение, жившее в нём, загрузило оба
     * источника за каждый его день. Загрузка дневная у обоих (period
     * raw-документа — день выгрузки), поэтому день считается загруженным,
     * когда за него есть документ, — пропуск внутри периода виден так же,
     * как не загруженные начало и конец.
     *
     * Подключение «жило» в периоде, если его загрузки начались не позже
     * конца периода и продолжались после его начала: кабинет, отключённый
     * раньше, в периоде просто отсутствует и неполным его не делает.
     * Кабинет, сломавшийся раньше периода и не починенный, отсюда
     * неотличим от отключённого — о нём кричит контроль свежести
     * (`NotifyStaleAccountsAction`), а не главная.
     */
    public function complete(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        $notRejected = DayLoadSql::NOT_REJECTED;
        $days = <<<SQL
            (
                SELECT marketplace_account_id,
                       MIN(period) AS first_load,
                       MAX(period) AS last_load,
                       COUNT(DISTINCT period) FILTER (WHERE report_type = :salesReportType
                           AND period >= :from AND period <= :to) AS sales_days,
                       COUNT(DISTINCT period) FILTER (WHERE report_type = :accrualsReportType
                           AND period >= :from AND period <= :to) AS accruals_days
                FROM marketplace_raw_document AS d
                WHERE company_id = :companyId
                  AND report_type IN (:salesReportType, :accrualsReportType)
                  AND {$notRejected}
                GROUP BY marketplace_account_id
            ) AS loads
            SQL;

        return $this->withParameters($companyId)
            ->select('COUNT(*) > 0 AND bool_and(sales_days = :days AND accruals_days = :days) AS complete')
            ->from($days)
            ->where('first_load <= :to')
            ->andWhere('last_load >= :from')
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('days', (int) $from->diff($to)->days + 1);
    }

    /**
     * @param array<string, mixed>|false $row
     */
    public static function mapComplete(array|false $row): bool
    {
        // Пустой агрегат без подключений отдаёт COUNT(*) = 0 — ложь,
        // а не NULL; NULL здесь означал бы ошибку запроса.
        return false !== $row && true === ($row['complete'] ?? null);
    }

    private function withParameters(string $companyId): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->setParameter('companyId', $companyId)
            ->setParameter('salesReportType', MarketplaceReportType::OzonPostingFboList)
            ->setParameter('accrualsReportType', MarketplaceReportType::OzonAccrualByDay);
    }
}
