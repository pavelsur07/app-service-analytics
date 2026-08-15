<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Интерфейс в Domain, реализация (HTTP) — Infrastructure/Connector/Ozon\
 * OzonProductInfoListClient. Тот же приём, что у OzonCatalogFetcher.
 *
 * Отдельный интерфейс, а не второй метод у OzonCatalogFetcher: это другой
 * эндпоинт с другим телом запроса, и подменять его в тестах приходится
 * отдельно от каталога — тест «имена не пришли, каталог не записан»
 * иначе не написать.
 */
interface OzonProductInfoFetcher
{
    /**
     * Наименования и прочие детали по списку идентификаторов площадки.
     * Возвращает тело ответа как есть, без разбора.
     *
     * Именно product_id, а не sku и не артикул продавца, хотя эндпоинт
     * принимает все три: с product_id снята фикстура, на которой
     * проверяется разбор. Форма запроса, не подтверждённая ответом
     * настоящего кабинета, — то, на чём этот проект уже обжигался.
     *
     * @param non-empty-list<int> $productIds
     */
    public function fetchNames(string $clientId, string $apiKey, array $productIds): string;
}
