<?php

declare(strict_types=1);

namespace App\Ingestion\Application\MessageHandler;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Application\Message\FetchOzonCatalogMessage;
use App\Ingestion\Domain\MarketplaceListing;
use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Ingestion\Domain\OzonCatalogFetcher;
use App\Ingestion\Domain\OzonProductListParser;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Клиент -> парсер -> замена каталога подключения целиком.
 *
 * Идемпотентен: replaceForAccount — апсерт по естественному ключу плюс
 * удаление исчезнувших, повторный запуск на том же ответе площадки
 * не меняет ни строки (first_seen_at сохраняется, last_seen_at
 * переставляется на тот же момент).
 *
 * **Ответы каталога намеренно не попадают в raw-слой.** Соблазн велик —
 * прослеживаемость ADR-006 требует raw для фактов, — но каталог фактом
 * не является, а последствие было бы скверным: raw-документ каталога
 * каждые полчаса обновлял бы received_at подключения, и сторож свежести
 * (NotifyStaleAccountsAction) считал бы синхронизацию продаж живой,
 * когда она встала. Контроль, который перестаёт срабатывать из-за
 * соседней исправной задачи, хуже отсутствующего.
 *
 * Страницы читаются циклом: у эндпоинта курсорная пагинация и другого
 * способа получить весь каталог нет. Запрет «запросов в цикле»
 * (CLAUDE.md §6) — про запросы к своей базе на каждый элемент, здесь же
 * цикл по страницам внешнего API, а запись в базу одна на весь каталог.
 */
#[AsMessageHandler]
final readonly class FetchOzonCatalogHandler
{
    /**
     * Потолок страниц: 1000 товаров на страницу, то есть до ста тысяч
     * артикулов у одного подключения. Нужен не ради лимита, а против
     * бесконечного цикла: если площадка начнёт возвращать один и тот же
     * курсор, обработчик обязан упасть громко, а не крутиться вечно,
     * держа воркер.
     */
    private const int MAX_PAGES = 100;

    private const int PAGE_SIZE = 1000;

    public function __construct(
        private IdentityFacade $identityFacade,
        private OzonCatalogFetcher $client,
        private OzonProductListParser $parser,
        private MarketplaceListingRepository $listings,
    ) {
    }

    public function __invoke(FetchOzonCatalogMessage $message): void
    {
        $target = $this->identityFacade->findOzonSyncTarget($message->companyId, $message->marketplaceAccountId);
        if (null === $target) {
            // Громкий отказ, а не молчаливый пропуск — как в обработчике
            // отгрузок: перевод подключения в broken (ADR-007) решает
            // не этот класс.
            throw new \RuntimeException("Marketplace account {$message->marketplaceAccountId} not found for company {$message->companyId}.");
        }

        $companyId = Uuid::fromString($target->companyId);
        $marketplaceAccountId = Uuid::fromString($target->marketplaceAccountId);
        $syncedAt = new \DateTimeImmutable();

        $listings = [];
        $lastId = '';
        $seenCursors = [];

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $rawBody = $this->client->fetchPage($target->clientId, $target->apiKey, $lastId, self::PAGE_SIZE);
            $parsed = $this->parser->parse($rawBody);

            foreach ($parsed->skus as $sku) {
                $listings[] = MarketplaceListing::seen($companyId, $marketplaceAccountId, $sku, $syncedAt);
            }

            // Признак конца — пустой курсор либо неполная страница.
            // Второе условие нужно потому, что площадка отдаёт непустой
            // last_id и на последней странице: без него каждая
            // синхронизация делала бы лишний запрос, а на ровно кратном
            // числе товаров — уходила бы на пустую страницу.
            if ('' === $parsed->lastId || $parsed->itemsOnPage < self::PAGE_SIZE) {
                $this->listings->replaceForAccount($target->companyId, $marketplaceAccountId, $listings, $syncedAt);

                return;
            }

            if (isset($seenCursors[$parsed->lastId])) {
                throw new \RuntimeException("Ozon вернул повторяющийся курсор каталога для подключения {$message->marketplaceAccountId} — выгрузка не двигается.");
            }
            $seenCursors[$parsed->lastId] = true;
            $lastId = $parsed->lastId;
        }

        // Досюда доходит только незавершённая выгрузка, и записывать её
        // нельзя: replaceForAccount удаляет всё, чего нет в переданном
        // списке, — неполный каталог стёр бы половину товаров продавца.
        throw new \RuntimeException(\sprintf('Каталог подключения %s не уместился в %d страниц — выгрузка не записана.', $message->marketplaceAccountId, self::MAX_PAGES));
    }
}
