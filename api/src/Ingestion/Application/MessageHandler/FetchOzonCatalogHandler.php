<?php

declare(strict_types=1);

namespace App\Ingestion\Application\MessageHandler;

use App\Identity\Application\Facade\IdentityFacade;
use App\Identity\Application\Facade\OzonSyncTarget;
use App\Ingestion\Application\Message\FetchOzonCatalogMessage;
use App\Ingestion\Domain\MarketplaceListing;
use App\Ingestion\Domain\MarketplaceListingPrice;
use App\Ingestion\Domain\MarketplaceListingPriceRepository;
use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\OzonAuthorizationFailure;
use App\Ingestion\Domain\OzonCatalogFetcher;
use App\Ingestion\Domain\OzonListingPrice;
use App\Ingestion\Domain\OzonListingPriceParser;
use App\Ingestion\Domain\OzonProductInfoFetcher;
use App\Ingestion\Domain\OzonProductInfoListParser;
use App\Ingestion\Domain\OzonProductListPage;
use App\Ingestion\Domain\OzonProductListParser;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Клиент -> парсер -> замена каталога подключения целиком.
 *
 * Запросов к площадке на страницу два: /v3/product/list отдаёт sku
 * и артикул продавца, но не наименование — его приходится брать
 * /v3/product/info/list по тем же товарам.
 *
 * Идемпотентен целиком: raw дедуплицируется по естественному ключу,
 * каталог обновляется только при фактическом изменении артикула или
 * имени (DoctrineMarketplaceListingWriter), повторный запуск на том же
 * ответе площадки не меняет ни строки.
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

    /**
     * Блокировка на подключение: два одновременных прогона каталога
     * для одного кабинета опасны дважды. `replaceForAccount` удаляет
     * всё, чего нет в переданном списке, — два списка, собранные
     * вперемешку, стирали бы товары друг друга. А история цены
     * (ADR-015) от параллельных прогонов получает худший исход:
     * подвисший старый прогон дописывает свою цену после нового,
     * и текущей на полчаса становится устаревшая.
     *
     * Условие §4 «идемпотентность держит уникальный индекс, а не
     * проверка» этим не подменяется, а дополняется: индекс закрывает
     * повтор, блокировка — одновременность. Тот же приём, что
     * в ScheduleOzonSyncCommand.
     *
     * Не дождавшийся замка прогон уходит молча и без ошибки: раз
     * синхронизация этого кабинета уже идёт, второй такой же не нужен.
     */
    private const int LOCK_TTL_SECONDS = 900;

    public function __construct(
        private LockFactory $lockFactory,
        private IdentityFacade $identityFacade,
        private OzonCatalogFetcher $client,
        private OzonProductInfoFetcher $infoClient,
        private OzonProductListParser $parser,
        private OzonProductInfoListParser $infoParser,
        private OzonListingPriceParser $priceParser,
        private MarketplaceListingRepository $listings,
        private MarketplaceListingPriceRepository $listingPrices,
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
        $lock = $this->lockFactory->createLock('ozon-catalog-'.$message->marketplaceAccountId, self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            return;
        }

        try {
            $this->sync($message);
        } finally {
            $lock->release();
        }
    }

    private function sync(FetchOzonCatalogMessage $message): void
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
            [$infoBody, $infoDocumentId] = $this->fetchProductInfo($target, $companyId, $marketplaceAccountId, $period, $parsed);
            $names = null === $infoBody ? [] : $this->infoParser->parse($infoBody);

            // Цены пишутся страницей, а не копятся до конца цикла
            // (CLAUDE.md §6: пакет сбрасывается порциями по ходу,
            // иначе память растёт линейно объёму каталога). Запрос
            // один на страницу, не на артикул, — запрета «запросов
            // в цикле» это не нарушает.
            //
            // В отличие от каталога, частичная запись здесь безвредна:
            // история цен только дописывается, ничего не заменяя.
            // Оборвавшаяся выгрузка оставит цены прочитанных страниц,
            // и это лучше, чем не оставить ничего.
            $this->listingPrices->recordChanged($target->companyId, $this->pricesOf(
                $infoBody,
                $companyId,
                $marketplaceAccountId,
                $infoDocumentId,
                $syncedAt,
            ));

            foreach ($parsed->items as $item) {
                $listings[] = MarketplaceListing::seen(
                    $companyId,
                    $marketplaceAccountId,
                    $item->sku,
                    $item->offerId,
                    $names[$item->sku] ?? null,
                    $syncedAt,
                );
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

    /**
     * Наименования страницы: второй запрос к площадке, потому что
     * /v3/product/list поля name не отдаёт вовсе.
     *
     * Запрос один на страницу, а не на товар, — и это ровно то, чего
     * требует запрет «запросов в цикле» (CLAUDE.md §6): связанные данные
     * берутся одним запросом по списку идентификаторов. Одним запросом
     * на весь каталог их взять нельзя: эндпоинт принимает не больше
     * тысячи идентификаторов за раз, столько же, сколько отдаёт страница
     * каталога, — нарезка на пачки существовала бы всё равно, только
     * своя вместо готовой.
     *
     * Отказ здесь громкий, а не «запишем без имён». Тихая деградация
     * стоила бы вопроса «почему у половины товаров нет названий» через
     * месяц, когда искать причину уже негде. Каталог заменяется целиком
     * каждый тик, поэтому пропуск одного тика не стоит ничего, а падение
     * видно: ретраи, а при исчерпании — failed-очередь и трекер.
     *
     * Исключение то же, что и у остальных обработчиков: отказ авторизации
     * — не техническая ошибка, а событие жизненного цикла подключения
     * (ADR-007). Он проброшен наверх, где обрабатывается общим порядком.
     *
     * Отдаёт сырое тело и идентификатор raw-документа, а не разобранные
     * имена: из того же ответа берутся ещё и цены (ADR-015), а документ
     * нужен строке истории цены как происхождение (ADR-006,
     * «Прослеживаемость»).
     *
     * @return array{0: string|null, 1: Uuid|null}
     */
    private function fetchProductInfo(
        OzonSyncTarget $target,
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        \DateTimeImmutable $period,
        OzonProductListPage $page,
    ): array {
        $productIds = $page->productIds();
        if ([] === $productIds) {
            return [null, null];
        }

        $rawBody = $this->infoClient->fetchNames($target->clientId, $target->apiKey, $productIds);

        $documentId = $this->rawDocuments->add(MarketplaceRawDocument::capture(
            companyId: $companyId,
            marketplaceAccountId: $marketplaceAccountId,
            reportType: MarketplaceReportType::OzonProductInfoList,
            period: $period,
            rawBody: $rawBody,
        ));

        return [$rawBody, $documentId];
    }

    /**
     * @return list<MarketplaceListingPrice>
     */
    private function pricesOf(
        ?string $infoBody,
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        ?Uuid $rawDocumentId,
        \DateTimeImmutable $seenAt,
    ): array {
        if (null === $infoBody || null === $rawDocumentId) {
            return [];
        }

        return array_map(
            static fn (OzonListingPrice $price): MarketplaceListingPrice => MarketplaceListingPrice::seen(
                companyId: $companyId,
                marketplaceAccountId: $marketplaceAccountId,
                marketplaceSku: $price->marketplaceSku,
                changedAt: $seenAt,
                price: $price->price,
                oldPrice: $price->oldPrice,
                rawDocumentId: $rawDocumentId,
            ),
            $this->priceParser->parse($infoBody),
        );
    }
}
