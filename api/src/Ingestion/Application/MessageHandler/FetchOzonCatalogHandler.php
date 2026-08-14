<?php

declare(strict_types=1);

namespace App\Ingestion\Application\MessageHandler;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Application\Message\FetchOzonCatalogMessage;
use App\Ingestion\Domain\MarketplaceListing;
use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\OzonAuthorizationFailure;
use App\Ingestion\Domain\OzonCatalogFetcher;
use App\Ingestion\Domain\OzonProductListParser;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Клиент -> парсер -> замена каталога подключения целиком.
 *
 * Идемпотентен целиком: raw дедуплицируется по естественному ключу,
 * каталог вставляется без обновления (в строке нет изменяемых колонок),
 * повторный запуск на том же ответе площадки не меняет ни строки.
 *
 * Ответ сохраняется в raw-слой до разбора, как и у отгрузок (ADR-006):
 * неудача разбора не должна терять сырьё, а строка каталога обязана
 * иметь происхождение. Сначала пробовали обойтись без raw — из опасения,
 * что документы каталога будут обновлять отметку свежести подключения
 * и маскировать вставшую синхронизацию продаж. Опасение верное,
 * но лечится оно не пропуском raw, а фильтром по типу отчёта в самом
 * стороже (RecentlyIngestedAccountsQuery).
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

    /**
     * Бизнес-дата raw-документа — сегодня в часовом поясе площадки
     * (ADR-009). У каталога периода нет, но period входит в естественный
     * ключ raw-слоя: без него побайтово одинаковый ответ дедуплицировался
     * бы навсегда, и следа сегодняшней синхронизации не осталось бы.
     */
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private IdentityFacade $identityFacade,
        private OzonCatalogFetcher $client,
        private OzonProductListParser $parser,
        private MarketplaceListingRepository $listings,
        private MarketplaceRawDocumentRepository $rawDocuments,
    ) {
    }

    /**
     * Отказ авторизации площадки — не техническая ошибка, а событие
     * жизненного цикла подключения (ADR-007): ключ отозван или перевыпущен
     * в кабинете. Повторять такой запрос бессмысленно, поэтому сообщение
     * не падает в очередь отказов, а завершается: подключение переведено
     * в broken, клиент получил письмо, планировщик его больше не возьмёт.
     * Молчаливой остановкой это не является — в том и смысл письма.
     *
     * Остальные 4xx (и все 5xx) остаются исключениями и уходят в ретрай
     * и в трекер: лимит запросов, неверный период, сбой площадки лечатся
     * повтором, а не переподключением кабинета.
     */
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
        $period = (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->setTime(0, 0);

        $listings = [];
        $lastId = '';
        $seenCursors = [];

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            try {
                $rawBody = $this->client->fetchPage($target->clientId, $target->apiKey, $lastId, self::PAGE_SIZE);
            } catch (\Throwable $failure) {
                if (!OzonAuthorizationFailure::isAuthorizationFailure($failure)) {
                    throw $failure;
                }

                // Прочитанные страницы не записываются: replaceForAccount
                // удаляет всё, чего нет в списке, и половина каталога стёрла
                // бы остальные товары продавца.
                $this->identityFacade->markOzonAccountBroken($message->companyId, $message->marketplaceAccountId);

                return;
            }

            // Каждая страница — отдельный документ: тела разные, и общий
            // ключ raw-слоя (company, account, тип, период, хэш тела)
            // разводит их сам, без номера страницы в ключе.
            $this->rawDocuments->add(MarketplaceRawDocument::capture(
                companyId: $companyId,
                marketplaceAccountId: $marketplaceAccountId,
                reportType: MarketplaceReportType::OzonProductList,
                period: $period,
                rawBody: $rawBody,
            ));

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
                $this->listings->replaceForAccount($target->companyId, $marketplaceAccountId, $listings);

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
