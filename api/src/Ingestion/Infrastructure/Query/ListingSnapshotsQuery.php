<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

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
 * Отдаёт QueryBuilder, как и все Query-классы проекта (CLAUDE.md §5):
 * выполняет и разбирает Facade. Ограничение сверху здесь не нужно —
 * запрашивается ровно столько карточек, сколько прислал вызывающий,
 * а тот уже ограничен лимитом своего запроса.
 *
 * **Кабинет участвует в отборе наравне с артикулом.** Без него после
 * переподключения магазина в истории остаются строки обоих кабинетов,
 * и выборка взяла бы цену чужого: соинвест вышел бы правдоподобным
 * и неверным, а от настоящего его не отличить.
 */
final readonly class ListingSnapshotsQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<ListingSnapshotRow>
     */
    /**
     * @param list<ListingSnapshotCriteria> $criteria
     */
    public function build(string $companyId, array $criteria): QueryBuilder
    {
        $values = [];
        $params = ['companyId' => $companyId];
        foreach ($criteria as $i => $wanted) {
            $values[] = "(:sku{$i}, :account{$i}::uuid, :at{$i}::timestamp(0))";
            $params["sku{$i}"] = $wanted->marketplaceSku;
            $params["account{$i}"] = $wanted->marketplaceAccountId;
            $params["at{$i}"] = $wanted->at->format('Y-m-d H:i:s');
        }

        // Список VALUES с плейсхолдерами, а не массив Postgres: тип
        // ArrayParameterType разворачивается в перечисление для IN,
        // а не в литерал массива, и собирать литерал руками значило бы
        // заводить своё экранирование там, где его сейчас нет. Тот же
        // приём, что у писателей фактов этого модуля.
        $asked = implode(', ', $values);

        $from = <<<SQL
            (VALUES {$asked}) AS asked(sku, account_id, at)
            LEFT JOIN marketplace_listing listing
                   ON listing.company_id = :companyId
                  AND listing.marketplace_account_id = asked.account_id
                  AND listing.marketplace_sku = asked.sku
            LEFT JOIN LATERAL (
                SELECT history.price_minor, history.currency
                FROM marketplace_listing_price history
                WHERE history.company_id = :companyId
                  AND history.marketplace_account_id = asked.account_id
                  AND history.marketplace_sku = asked.sku
                  AND history.changed_at <= asked.at
                ORDER BY history.changed_at DESC
                LIMIT 1
            ) price ON true
            SQL;

        $qb = $this->connection->createQueryBuilder()
            ->select('asked.sku AS marketplace_sku', 'listing.name', 'price.price_minor', 'price.currency')
            ->from($from);

        foreach ($params as $name => $value) {
            $qb->setParameter($name, $value);
        }

        return $qb;
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
