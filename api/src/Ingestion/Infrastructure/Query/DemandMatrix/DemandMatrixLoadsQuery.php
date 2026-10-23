<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DemandMatrix;

use App\Ingestion\Domain\MarketplaceReportType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;

/**
 * Полнота загрузки продаж окна матрицы по подключениям (ADR-038):
 * у каждого дня окна последний прогон успешен, а вчерашний день загружен
 * успешно после своего окончания. Вчера перезагружается каждым тиком,
 * поэтому успех после полуночи значит, что день загружен целиком. День
 * без отметки — не загруженный: его не грузили или прогон оборвался
 * до отметки (окно 28 дней целиком после ADR-038, рескан — 30 дней).
 *
 * Company-scoped, по первичному ключу отметок; строк не больше, чем
 * подключений в списке.
 */
final readonly class DemandMatrixLoadsQuery
{
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<string> $accountIds подключения Ozon компании; не пуст
     */
    public function build(string $companyId, \DateTimeImmutable $today, array $accountIds): QueryBuilder
    {
        $parameters = DemandMatrixSql::parameters($companyId, $today);
        // Отметки пишутся в UTC; «после полуночи» — по Москве.
        $dayEnd = new \DateTimeImmutable($today->format('Y-m-d'), new \DateTimeZone(self::TIMEZONE));

        return $this->connection->createQueryBuilder()
            ->select(
                'marketplace_account_id',
                'BOOL_OR(period = :windowEnd AND failed_at IS NULL AND completed_at >= :dayEnd) AS last_day_complete',
                'COUNT(DISTINCT period) FILTER (WHERE failed_at IS NULL AND completed_at IS NOT NULL) AS complete_days',
            )
            ->from('marketplace_day_load')
            ->where('company_id = :companyId')
            ->andWhere('marketplace_account_id IN (:accounts)')
            ->andWhere('report_type = :reportType')
            ->andWhere('period BETWEEN :demandFrom AND :windowEnd')
            ->groupBy('marketplace_account_id')
            ->setParameter('companyId', $companyId)
            ->setParameter('accounts', $accountIds, ArrayParameterType::STRING)
            ->setParameter('reportType', MarketplaceReportType::OzonPostingFboList)
            ->setParameter('demandFrom', $parameters['demandFrom'])
            ->setParameter('windowEnd', $parameters['windowEnd'])
            ->setParameter('dayEnd', $dayEnd->setTimezone(new \DateTimeZone('UTC')), Types::DATETIME_IMMUTABLE);
    }

    /**
     * Подключения из списка, чьи продажи окна загружены не полностью:
     * хотя бы один день окна без успешной отметки или вчера не загружено
     * после полуночи. Подключение без единой отметки в окне — тоже.
     *
     * @param list<string>               $accountIds
     * @param list<array<string, mixed>> $rows
     */
    public static function incompleteCount(array $accountIds, array $rows): int
    {
        $complete = [];
        foreach ($rows as $row) {
            if (true === $row['last_day_complete'] && DemandMatrixSql::int($row['complete_days']) >= DemandMatrixSql::WINDOW_DAYS) {
                $complete[DemandMatrixSql::string($row['marketplace_account_id'])] = true;
            }
        }

        return \count(array_filter($accountIds, static fn (string $id): bool => !isset($complete[$id])));
    }
}
