<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Наименование карточки и цена, действовавшая на указанный момент
 * (ADR-015, ADR-016). DBAL, без гидрации сущностей (CLAUDE.md §5).
 *
 * Один запрос на весь экран, а не на артикул: моменты приезжают
 * списком `VALUES` и соединяются с историей боковым подзапросом.
 * Запрос на строку был бы запросом в цикле (§6), а строк на экране
 * до полусотни.
 *
 * `company_id` в условии самого SQL, не фильтром после выборки, —
 * изоляция арендаторов проверяется запросом (§1).
 *
 * В отличие от `build()`-запросов этого модуля, метод сразу отдаёт
 * строки, а не QueryBuilder: выборка не листается и не расширяется
 * вызывающим — у неё ровно один потребитель и фиксированная форма.
 * Ограничение сверху всё равно есть: список моментов приходит
 * от вызывающего, а тот уже ограничен лимитом своего запроса.
 */
final readonly class ListingSnapshotsQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param array<string, \DateTimeImmutable> $momentsBySku
     *
     * @return list<ListingSnapshotRow>
     */
    public function fetch(string $companyId, array $momentsBySku): array
    {
        $values = [];
        $params = ['companyId' => $companyId];
        $i = 0;
        foreach ($momentsBySku as $sku => $at) {
            $values[] = "(:sku{$i}, :at{$i}::timestamp(0))";
            $params["sku{$i}"] = $sku;
            $params["at{$i}"] = $at->format('Y-m-d H:i:s');
            ++$i;
        }

        if ([] === $values) {
            return [];
        }

        $asked = implode(', ', $values);

        // Список VALUES с плейсхолдерами, а не массив Postgres: тип
        // ArrayParameterType разворачивается в перечисление для IN,
        // а не в литерал массива, и собирать литерал руками значило бы
        // заводить своё экранирование там, где его сейчас нет. Тот же
        // приём, что у писателей фактов этого модуля.
        $sql = <<<SQL
            SELECT asked.sku AS marketplace_sku,
                   listing.name,
                   price.price_minor,
                   price.currency
            FROM (VALUES {$asked}) AS asked(sku, at)
            LEFT JOIN marketplace_listing listing
                   ON listing.company_id = :companyId
                  AND listing.marketplace_sku = asked.sku
            LEFT JOIN LATERAL (
                SELECT history.price_minor, history.currency
                FROM marketplace_listing_price history
                WHERE history.company_id = :companyId
                  AND history.marketplace_sku = asked.sku
                  AND history.changed_at <= asked.at
                ORDER BY history.changed_at DESC
                LIMIT 1
            ) price ON true
            SQL;

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(self::mapRow(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): ListingSnapshotRow
    {
        $sku = $row['marketplace_sku'];
        if (!\is_string($sku)) {
            throw new \UnexpectedValueException('Expected a string marketplace_sku.');
        }

        $name = $row['name'];
        $priceMinor = $row['price_minor'];
        $currency = $row['currency'];

        return new ListingSnapshotRow(
            marketplaceSku: $sku,
            name: \is_string($name) ? $name : null,
            priceMinor: null === $priceMinor ? null : (int) (\is_int($priceMinor) || \is_string($priceMinor) ? $priceMinor : throw new \UnexpectedValueException('Expected an int-like price_minor.')),
            currency: \is_string($currency) ? $currency : null,
        );
    }
}
