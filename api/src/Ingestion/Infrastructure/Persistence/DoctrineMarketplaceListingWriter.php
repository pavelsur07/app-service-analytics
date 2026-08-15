<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\MarketplaceListing;
use App\Ingestion\Domain\MarketplaceListingRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * DBAL, не ORM: каталог наполняется синхронизацией, не человеком
 * (CLAUDE.md §6). Тот же приём, что у DoctrineSalesFactWriter —
 * INSERT ... ON CONFLICT по естественному ключу, один запрос на чанк,
 * не запрос на строку.
 *
 * Отличие от фактов: здесь ещё и удаление. Площадка отдаёт весь список,
 * поэтому строки, не пришедшие в эту синхронизацию, из каталога уходят.
 * Признак ухода — отсутствие артикула в самой выгрузке, а не отметка
 * времени. Отметку пробовали: `last_seen_at` хранится с точностью
 * до секунды, и две синхронизации внутри одной секунды получают
 * одинаковое значение — исчезнувший товар переживал такую пару
 * незамеченным. Корректность каталога не должна зависеть от точности
 * часов и от того, насколько быстро пришёл ответ площадки.
 *
 * Список артикулов передаётся одним jsonb-параметром, не набором
 * плейсхолдеров: у подключения их бывают десятки тысяч, а запрос
 * должен остаться одним запросом.
 *
 * Всё в одной транзакции: между «залили новое» и «удалили старое»
 * каталог не должен быть виден ни пустым, ни задвоенным — оверлей
 * в этот момент спросил бы «мой ли товар» и получил неверный ответ.
 */
final readonly class DoctrineMarketplaceListingWriter implements MarketplaceListingRepository
{
    private const int CHUNK_SIZE = 500;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function replaceForAccount(
        string $companyId,
        Uuid $marketplaceAccountId,
        array $listings,
    ): void {
        $this->connection->transactional(function () use ($companyId, $marketplaceAccountId, $listings): void {
            foreach (array_chunk($listings, self::CHUNK_SIZE) as $chunk) {
                $this->upsertChunk($chunk);
            }

            $this->deleteVanished($companyId, $marketplaceAccountId, $listings);
        });
    }

    /**
     * @param list<MarketplaceListing> $listings
     */
    private function upsertChunk(array $listings): void
    {
        if ([] === $listings) {
            return;
        }

        $valuesSql = [];
        $params = [];
        foreach ($listings as $i => $listing) {
            $valuesSql[] = "(:companyId{$i}, :marketplaceAccountId{$i}, :marketplaceSku{$i}, :offerId{$i}, :name{$i}, :firstSeenAt{$i})";

            $params["companyId{$i}"] = $listing->companyId()->toRfc4122();
            $params["marketplaceAccountId{$i}"] = $listing->marketplaceAccountId()->toRfc4122();
            $params["marketplaceSku{$i}"] = $listing->marketplaceSku();
            $params["offerId{$i}"] = $listing->offerId();
            $params["name{$i}"] = $listing->name();
            $params["firstSeenAt{$i}"] = $listing->firstSeenAt()->format('Y-m-d H:i:sP');
        }

        // DO UPDATE, а не DO NOTHING: артикул продавца и наименование
        // селлер правит в кабинете, и карточка, у которой сменилось имя,
        // обязана сменить его и у нас.
        //
        // Но `WHERE ... IS DISTINCT FROM` обязателен: без него повторный
        // прогон на том же ответе площадки переписывал бы каждую строку,
        // и обработчик перестал бы быть идемпотентным (CLAUDE.md §4).
        // Тот же приём, что у sales_fact.
        //
        // COALESCE на имени: отсутствие имени в ответе — не пустое имя.
        // Если запрос имён по этой карточке ничего не дал, известное имя
        // остаётся. Обратного случая у площадки нет — имя у карточки
        // обязательное, пустым оно не приходит.
        //
        // first_seen_at в SET отсутствует намеренно: это момент первой
        // встречи, и товар, пришедший снова, новым не становится.
        $sql = <<<SQL
            INSERT INTO marketplace_listing
                (company_id, marketplace_account_id, marketplace_sku, offer_id, name, first_seen_at)
            VALUES {$this->joinValues($valuesSql)}
            ON CONFLICT (company_id, marketplace_account_id, marketplace_sku)
            DO UPDATE SET
                offer_id = EXCLUDED.offer_id,
                name = COALESCE(EXCLUDED.name, marketplace_listing.name)
            WHERE marketplace_listing.offer_id IS DISTINCT FROM EXCLUDED.offer_id
               OR marketplace_listing.name IS DISTINCT FROM COALESCE(EXCLUDED.name, marketplace_listing.name)
            SQL;

        $this->connection->executeStatement($sql, $params);
    }

    /**
     * @param list<MarketplaceListing> $listings
     */
    private function deleteVanished(
        string $companyId,
        Uuid $marketplaceAccountId,
        array $listings,
    ): void {
        $skus = array_map(
            static fn (MarketplaceListing $listing): string => $listing->marketplaceSku(),
            $listings,
        );

        // companyId в условии, хотя marketplace_account_id уже однозначен:
        // изоляция арендаторов держится на SQL, а не на том, что
        // вызывающий передал согласованную пару (CLAUDE.md §1).
        //
        // Пустой список — валидный случай (все товары сняты), и он обязан
        // очистить каталог подключения: NOT IN пустого множества истинно
        // для всех строк. Защита от неполной выгрузки не здесь — сюда
        // список попадает, только когда пройдены все страницы.
        // NOT EXISTS, а не NOT IN: у NOT IN с подзапросом семантика ломается
        // об единственный NULL — всё выражение становится NULL, и удаление
        // тихо не делает ничего. Прийти NULL сюда сегодня неоткуда (парсер
        // отдаёт непустые строки), но цена ошибки — каталог, который
        // перестал чиститься, и заметили бы это не скоро. NOT EXISTS
        // к тому же даёт планировщику обычный анти-join.
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM marketplace_listing AS l
                WHERE l.company_id = :companyId
                  AND l.marketplace_account_id = :marketplaceAccountId
                  AND NOT EXISTS (
                      SELECT 1
                      FROM jsonb_array_elements_text(:skus::jsonb) AS uploaded(sku)
                      WHERE uploaded.sku = l.marketplace_sku
                  )
                SQL,
            [
                'companyId' => $companyId,
                'marketplaceAccountId' => $marketplaceAccountId->toRfc4122(),
                'skus' => json_encode($skus, \JSON_THROW_ON_ERROR),
            ],
        );
    }

    /**
     * @param list<string> $valuesSql
     */
    private function joinValues(array $valuesSql): string
    {
        return implode(', ', $valuesSql);
    }
}
