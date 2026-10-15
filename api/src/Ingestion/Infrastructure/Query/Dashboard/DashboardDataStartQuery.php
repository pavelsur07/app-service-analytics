<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Dashboard;

use App\Ingestion\Domain\MarketplaceReportType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * С какого дня у компании есть данные главной: первый загруженный день
 * отправлений и первый загруженный день ленты начислений.
 *
 * **Меряется загрузка, а не факты** (CLAUDE.md, «Наблюдаемость»):
 * день без единого заказа — законный ноль, а день до подключения —
 * отсутствие данных, и по фактам их не различить. Признак — raw-документ:
 * period входит в его естественный ключ (ADR-006), строка есть за каждый
 * обработанный день, даже с пустым ответом площадки.
 *
 * Без этой отметки сравнение с периодом до подключения показывало бы
 * «+∞%» или «рост в сто раз» — правдоподобно и неверно.
 *
 * Отдельный индекс не нужен по той же причине, что у
 * `ExpenseCoverageQuery`: запрос идёт по префиксу `company_id`
 * уникального ключа raw-слоя, документов у компании тысячи.
 */
final readonly class DashboardDataStartQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function build(string $companyId): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'MIN(period) FILTER (WHERE report_type = :salesReportType) AS sales_start',
                'MIN(period) FILTER (WHERE report_type = :accrualsReportType) AS accruals_start',
            )
            ->from('marketplace_raw_document')
            ->where('company_id = :companyId')
            ->andWhere('report_type IN (:salesReportType, :accrualsReportType)')
            ->setParameter('companyId', $companyId)
            ->setParameter('salesReportType', MarketplaceReportType::OzonPostingFboList)
            ->setParameter('accrualsReportType', MarketplaceReportType::OzonAccrualByDay);
    }

    /**
     * Позднейший из двух первых дней: виджеты читают оба источника,
     * и полон период только с момента, когда загружены оба. NULL —
     * какой-то из источников не загружался ни разу.
     *
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): ?string
    {
        $sales = $row['sales_start'] ?? null;
        $accruals = $row['accruals_start'] ?? null;
        if (!\is_string($sales) || !\is_string($accruals)) {
            return null;
        }

        return max(substr($sales, 0, 10), substr($accruals, 0, 10));
    }
}
