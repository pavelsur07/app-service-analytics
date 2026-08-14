<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Когда по каждому подключению компании последний раз приходила выгрузка,
 * отдельно по типу отчёта. DBAL, без гидрации (CLAUDE.md §5).
 *
 * companyId первым параметром и в самом SQL — обычное company-scoped
 * чтение, в отличие от RecentlyIngestedAccountsQuery, который смотрит
 * на все компании сразу и потому живёт в узком слое Deptrac.
 *
 * Меряется raw-слоем, а не фактами, по той же причине, что и сторож
 * свежести: у продавца бывает день без заказов, и «последний факт»
 * показал бы исправную синхронизацию вставшей. Тип отчёта в результате,
 * а не в условии: экран показывает продажи и каталог отдельными
 * строками — видно в том числе, что каталог сторожем не отслеживается.
 */
final readonly class AccountFreshnessQuery
{
    /**
     * Подключений у компании единицы, типов отчёта — два. Потолок против
     * списка без предела (§5), не бизнес-ограничение.
     */
    public const int MAX_RESULTS = 200;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function build(string $companyId): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('marketplace_account_id', 'report_type', 'MAX(received_at) AS last_received_at')
            ->from('marketplace_raw_document')
            ->where('company_id = :companyId')
            ->setParameter('companyId', $companyId)
            ->groupBy('marketplace_account_id')
            ->addGroupBy('report_type')
            // +1 — см. CompanyConnectionsQuery: молча обрезанная свежесть
            // показала бы «загрузок не было» по исправной выгрузке.
            ->setMaxResults(self::MAX_RESULTS + 1);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): AccountFreshnessRow
    {
        return new AccountFreshnessRow(
            marketplaceAccountId: self::stringValue($row['marketplace_account_id']),
            reportType: self::stringValue($row['report_type']),
            lastReceivedAt: self::isoUtc($row['last_received_at']),
        );
    }

    /**
     * Postgres отдаёт `timestamp without time zone` строкой «Y-m-d H:i:s».
     * Браузер разбирает её как местное время, и 09:00 UTC превращается
     * в 09:00 по часам пользователя — цифра сдвигается на величину его
     * пояса, а <time dateTime> получает значение, недопустимое в HTML.
     * Приложение пишет эти колонки в UTC (date.timezone=UTC), поэтому
     * здесь UTC и объявляется явно.
     */
    private static function isoUtc(mixed $value): string
    {
        $raw = self::stringValue($value);

        return (new \DateTimeImmutable($raw, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }

    private static function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string value in a raw document freshness row.');
        }

        return $value;
    }
}
