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
 * Запросы к площадке в цикле по пачкам — цикл внешнего API, как
 * у каталога; запись в базу одна на прогон (CLAUDE.md §6).
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

        $skus = $this->skus->fetch($target->companyId, $target->marketplaceAccountId);
        if ([] === $skus) {
            // Каталог ещё не загружен (подключение только что создано) или
            // пуст — запрашивать нечего. Не ошибка: следующий прогон возьмёт
            // каталог, а сторож свежести напомнит, если снимков так и нет.
            return;
        }

        $rawDocumentIds = [];
        $facts = [];
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
            $rawDocumentIds[] = $rawDocumentId;
            foreach ($this->parser->parse($rawBody, $companyId, $accountId, $snapshotDate, $rawDocumentId) as $fact) {
                $facts[] = $fact;
            }
        }

        $this->snapshots->replaceDay(
            $target->companyId,
            $accountId,
            $snapshotDate,
            $startedAt,
            $skus,
            $rawDocumentIds,
            $facts,
        );
    }
}
