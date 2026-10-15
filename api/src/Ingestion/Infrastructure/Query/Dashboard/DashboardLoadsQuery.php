<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Dashboard;

use App\Ingestion\Domain\MarketplaceReportType;
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
 * ответом площадки.
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
    /**
     * Первый и последний загруженный день каждого источника на подключение.
     * Подключений у компании единицы.
     */
    private const string LOADS = <<<'SQL'
        (
            SELECT marketplace_account_id,
                   MIN(period) FILTER (WHERE report_type = :salesReportType) AS sales_first,
                   MAX(period) FILTER (WHERE report_type = :salesReportType) AS sales_last,
                   MIN(period) FILTER (WHERE report_type = :accrualsReportType) AS accruals_first,
                   MAX(period) FILTER (WHERE report_type = :accrualsReportType) AS accruals_last
            FROM marketplace_raw_document
            WHERE company_id = :companyId
              AND report_type IN (:salesReportType, :accrualsReportType)
            GROUP BY marketplace_account_id
        ) AS loads
        SQL;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * С какого дня данные есть у всех подключений: позднейший из первых
     * загруженных дней обоих источников. NULL — у какого-то подключения
     * один из источников не загружался ни разу. Для подписи на экране;
     * решение о сравнении принимает `complete`.
     */
    public function dataStart(string $companyId): QueryBuilder
    {
        return $this->withParameters($companyId)
            ->select(
                'CASE WHEN bool_and(sales_first IS NOT NULL AND accruals_first IS NOT NULL)'
                .' THEN MAX(GREATEST(sales_first, accruals_first)) END AS data_starts_on',
            )
            ->from(self::LOADS);
    }

    /**
     * Полон ли период: каждое подключение, жившее в нём, загрузило оба
     * источника с первого дня периода по последний. Подключение «жило»,
     * если загружалось после начала периода и начало загружаться до его
     * конца: кабинет, остановленный раньше периода, в нём просто отсутствует,
     * а не делает его неполным.
     */
    public function complete(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        return $this->withParameters($companyId)
            ->select(
                'COUNT(*) > 0 AND bool_and(COALESCE('
                .'sales_first <= :from AND accruals_first <= :from'
                .' AND sales_last >= :to AND accruals_last >= :to, FALSE)) AS complete',
            )
            ->from(self::LOADS)
            ->where('GREATEST(sales_last, accruals_last) >= :from')
            ->andWhere('LEAST(sales_first, accruals_first) <= :to')
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'));
    }

    /**
     * @param array<string, mixed>|false $row
     */
    public static function mapDataStart(array|false $row): ?string
    {
        $value = false === $row ? null : ($row['data_starts_on'] ?? null);

        return \is_string($value) ? substr($value, 0, 10) : null;
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
