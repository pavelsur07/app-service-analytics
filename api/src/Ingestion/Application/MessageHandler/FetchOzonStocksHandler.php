<?php

declare(strict_types=1);

namespace App\Ingestion\Application\MessageHandler;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Application\Message\FetchOzonStocksMessage;
use App\Ingestion\Application\OzonAccountBrokenLogger;
use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\OzonAnalyticsStocksParser;
use App\Ingestion\Domain\OzonAuthorizationFailure;
use App\Ingestion\Domain\OzonStockFetcher;
use App\Ingestion\Domain\StockSnapshotFact;
use App\Ingestion\Domain\StockSnapshotRepository;
use App\Ingestion\Infrastructure\Query\AccountListingSkusQuery;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Прогон снимка остатков (ADR-034): все пачки SKU каталога подключения
 * одной обработкой → raw каждой пачки → замена дня целиком.
 *
 * Снимок полезен только целым: площадка не отдаёт строку при нулевом
 * остатке, и половина пачек выдала бы «ноль» по товарам второй половины.
 * Поэтому любой отказ посреди прогона — исключение до записи факта:
 * день остаётся прежним, сообщение уходит в повтор, а повтор — новый
 * прогон с новым started_at. Raw уже полученных пачек остаётся (ADR-006),
 * но в отметку дня не попадает.
 *
 * Идемпотентен: повторная запись того же прогона ничего не меняет,
 * устаревший прогон не затирает свежий (DoctrineStockSnapshotWriter).
 * Блокировки прогонов нет намеренно (ADR-034): каждый прогон заменяет
 * день целиком и побеждает более поздний по началу.
 *
 * Цикл по пачкам — цикл внешнего API, как у каталога: на каждый ответ
 * площадки — один raw-документ до разбора (ADR-006), это не «связанные
 * данные в цикле» из CLAUDE.md §6. Факты в памяти не копятся: запись дня
 * читает их лениво из raw по одной пачке и вставляет порциями — память
 * ограничена пачкой, а не каталогом, и день заодно собирается ровно из тех
 * документов, что записаны в его отметку.
 */
#[AsMessageHandler]
final readonly class FetchOzonStocksHandler
{
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private IdentityFacade $identityFacade,
        private OzonAccountBrokenLogger $brokenLogger,
        private AccountListingSkusQuery $skus,
        private OzonStockFetcher $client,
        private OzonAnalyticsStocksParser $parser,
        private MarketplaceRawDocumentRepository $rawDocuments,
        private StockSnapshotRepository $snapshots,
    ) {
    }

    public function __invoke(FetchOzonStocksMessage $message): void
    {
        $target = $this->identityFacade->findOzonSyncTarget($message->companyId, $message->marketplaceAccountId);
        if (null === $target) {
            throw new \RuntimeException("Marketplace account {$message->marketplaceAccountId} not found for company {$message->companyId}.");
        }

        $startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $snapshotDate = $startedAt->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0);
        $companyId = Uuid::fromString($target->companyId);
        $accountId = Uuid::fromString($target->marketplaceAccountId);

        $skus = AccountListingSkusQuery::mapColumn(
            $this->skus->build($target->companyId, $target->marketplaceAccountId)->executeQuery()->fetchFirstColumn(),
            $target->marketplaceAccountId,
        );
        if ([] === $skus) {
            if ($message->retryIfCatalogEmpty) {
                // Первый снимок нового кабинета: каталог ещё грузится.
                // Повтор очереди, а не тихий выход — иначе день потерян.
                throw new \RuntimeException("Каталог подключения {$message->marketplaceAccountId} ещё не загружен — первый снимок остатков отложен до повтора.");
            }

            // Ночной прогон по пустому каталогу — запрашивать нечего;
            // сторож свежести напомнит, если снимков так и нет.
            return;
        }

        $rawDocumentIds = [];
        foreach (array_chunk($skus, OzonAnalyticsStocksParser::BATCH_SIZE) as $batch) {
            try {
                $rawBody = $this->client->fetchStocks($target->clientId, $target->apiKey, $batch);
            } catch (\Throwable $failure) {
                if (!OzonAuthorizationFailure::isAuthorizationFailure($failure)) {
                    throw $failure;
                }
                // Событие жизненного цикла подключения (ADR-007), а не
                // техническая ошибка: неполный снимок не пишется.
                $this->brokenLogger->log($message->companyId, $message->marketplaceAccountId, 'stocks', $failure, $target->apiKey);
                $this->identityFacade->markOzonAccountBroken($message->companyId, $message->marketplaceAccountId);

                return;
            }

            $rawDocumentId = $this->rawDocuments->add(MarketplaceRawDocument::capture(
                companyId: $companyId,
                marketplaceAccountId: $accountId,
                reportType: MarketplaceReportType::OzonAnalyticsStocks,
                period: $snapshotDate,
                rawBody: $rawBody,
            ));
            // Разбор сразу — ради громкого отказа до записи дня: ответ
            // с ключами продолжения или без полей не должен дойти до замены.
            $this->parser->parse($rawBody, $companyId, $accountId, $snapshotDate, $rawDocumentId);
            $rawDocumentIds[] = $rawDocumentId;
            unset($rawBody);
        }

        $this->snapshots->replaceDay(
            $target->companyId,
            $accountId,
            $snapshotDate,
            $startedAt,
            $skus,
            $rawDocumentIds,
            $this->factsFromRaw($target->companyId, $companyId, $accountId, $snapshotDate, $rawDocumentIds),
        );
    }

    /**
     * Факты дня — из raw-документов прогона по одному (company-scoped
     * чтение тела, ADR-024): одновременно в памяти одна пачка.
     *
     * @param list<Uuid> $rawDocumentIds
     *
     * @return \Generator<int, StockSnapshotFact>
     */
    private function factsFromRaw(
        string $companyIdValue,
        Uuid $companyId,
        Uuid $accountId,
        \DateTimeImmutable $snapshotDate,
        array $rawDocumentIds,
    ): \Generator {
        foreach ($rawDocumentIds as $rawDocumentId) {
            $body = $this->rawDocuments->body($companyIdValue, $accountId, $rawDocumentId);
            foreach ($this->parser->parse($body, $companyId, $accountId, $snapshotDate, $rawDocumentId) as $fact) {
                yield $fact;
            }
        }
    }
}
