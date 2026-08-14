<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Подключения компании для экрана. DBAL, без гидрации сущностей
 * (CLAUDE.md §5): экран читает, а не редактирует.
 *
 * companyId первым параметром и в самом SQL — обычное company-scoped
 * чтение, исключений §1 здесь нет.
 *
 * Учётные данные не выбираются вовсе. Не «не показываем на экране»,
 * а не достаём из базы: шифротекст, случайно попавший в ответ API,
 * — это утечка, которую никакая правка фронтенда потом не отменит.
 */
final readonly class CompanyConnectionsQuery
{
    /**
     * Подключений у компании единицы. Потолок — против списка без предела
     * (§5), а не бизнес-ограничение; пагинации у справочника такого размера
     * не бывает.
     */
    public const int MAX_RESULTS = 50;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function build(string $companyId): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('id', 'marketplace', 'external_shop_id', 'state', 'created_at')
            ->from('marketplace_account')
            ->where('company_id = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('created_at', 'ASC')
            // +1 — чтобы отличить «ровно потолок» от «потолок превышен»:
            // тихо обрезанный список подключений выглядел бы как полный,
            // и клиент решил бы, что магазин отключён (§5).
            ->setMaxResults(self::MAX_RESULTS + 1);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): CompanyConnectionRow
    {
        return new CompanyConnectionRow(
            id: self::stringValue($row['id']),
            marketplace: self::stringValue($row['marketplace']),
            externalShopId: self::stringValue($row['external_shop_id']),
            state: self::stringValue($row['state']),
            createdAt: self::isoUtc($row['created_at']),
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
            throw new \UnexpectedValueException('Expected a string value in a marketplace account row.');
        }

        return $value;
    }
}
