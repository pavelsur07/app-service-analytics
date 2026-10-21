<?php

declare(strict_types=1);

namespace App\Ingestion\Application\MessageHandler;

use App\Identity\Application\Facade\IdentityFacade;
use App\Identity\Application\Facade\OzonSyncTarget;
use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use App\Ingestion\Application\OzonAccountBrokenLogger;
use App\Ingestion\Application\OzonFailureReason;
use App\Ingestion\Domain\DayLoadMark;
use App\Ingestion\Domain\MarketplaceDayLoadRepository;
use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\OzonAuthorizationFailure;
use App\Ingestion\Domain\OzonPostingFboListParser;
use App\Ingestion\Domain\OzonPostingPage;
use App\Ingestion\Domain\OzonPostingsFetcher;
use App\Ingestion\Domain\OzonPostingStatusParser;
use App\Ingestion\Domain\SalesFactRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Клиент (пакет 2) -> raw (пакет 3) -> парсер (пакет 4) -> upsert facts;
 * день листается страницами `/v3/posting/fbo/list` (ADR-042).
 * Идемпотентен целиком: raw дедуплицируется по естественному ключу
 * (ADR-006), sales_fact — upsert по своему (ADR-006); повторный запуск
 * на тех же входных данных не меняет результат.
 */
#[AsMessageHandler]
final readonly class FetchOzonPostingsHandler
{
    private const int LOCK_TTL_SECONDS = 900;

    /**
     * 50 страниц по 100 — 5 000 отправлений за день, в 50 раз больше
     * нынешних. Превышение — громкий отказ, а не обрезанный день.
     */
    private const int MAX_PAGES = 50;

    private const string REPORT_TYPE = MarketplaceReportType::OzonPostingFboList;

    // Europe/Moscow — константа коннектора Ozon, не настройка подключения
    // (ADR-009): у площадки нет пользовательских часовых поясов кабинета.
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private LockFactory $lockFactory,
        private IdentityFacade $identityFacade,
        private OzonAccountBrokenLogger $brokenLogger,
        private OzonPostingsFetcher $client,
        private OzonPostingFboListParser $parser,
        private OzonPostingStatusParser $statusParser,
        private MarketplaceRawDocumentRepository $rawDocuments,
        private MarketplacePostingStatusRepository $postingStatuses,
        private SalesFactRepository $salesFacts,
        private MarketplaceDayLoadRepository $dayLoads,
        private Connection $connection,
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
    public function __invoke(FetchOzonPostingsMessage $message): void
    {
        $lock = $this->lockFactory->createLock(
            'ozon-postings-'.$message->marketplaceAccountId.'-'.$message->businessDate,
            self::LOCK_TTL_SECONDS,
        );
        if (!$lock->acquire()) {
            return;
        }

        try {
            $this->sync($message, $lock);
        } finally {
            $lock->release();
        }
    }

    private function sync(FetchOzonPostingsMessage $message, SharedLockInterface $lock): void
    {
        $target = $this->identityFacade->findOzonSyncTarget($message->companyId, $message->marketplaceAccountId);
        if (null === $target) {
            // Подключение не найдено для этой компании — состояние broken
            // и уведомление клиента (ADR-007) вне tracer bullet; здесь —
            // громкий отказ, а не молчаливый пропуск.
            throw new \RuntimeException("Marketplace account {$message->marketplaceAccountId} not found for company {$message->companyId}.");
        }

        $timezone = new \DateTimeZone(self::TIMEZONE);
        $since = (new \DateTimeImmutable($message->businessDate, $timezone))->setTime(0, 0);
        $to = $since->modify('+1 day');

        try {
            $this->loadDay($message, $target, $since, $to, $lock);
        } catch (\Throwable $failure) {
            // Отметка загрузки (ADR-038): raw за день мог уже сохраниться,
            // а факты — нет, и без отметки день выглядел бы загруженным.
            // В причине — тело ответа: код без него не говорит, что не так.
            $this->dayLoads->mark($message->companyId, $message->marketplaceAccountId, [DayLoadMark::failed(self::REPORT_TYPE, $since, $since, OzonFailureReason::of($failure, $target->apiKey))], new \DateTimeImmutable());

            throw $failure;
        }
    }

    /**
     * День листается курсором `/v3/posting/fbo/list` (ADR-042): каждая
     * страница — raw до разбора; статусы и факты — одной транзакцией,
     * когда страниц больше нет. Отказ посреди листания оставляет raw
     * прочитанных страниц для расследования, но не половину дня в фактах.
     * Порядок тот же, что у возвратов (FetchOzonReturnsHandler).
     */
    private function loadDay(FetchOzonPostingsMessage $message, OzonSyncTarget $target, \DateTimeImmutable $since, \DateTimeImmutable $to, SharedLockInterface $lock): void
    {
        $companyId = Uuid::fromString($target->companyId);
        $marketplaceAccountId = Uuid::fromString($target->marketplaceAccountId);
        $cursor = '';
        /** @var array<string, true> $seenCursors */
        $seenCursors = [];
        /** @var list<Uuid> $pages */
        $pages = [];

        for ($pageNumber = 1; $pageNumber <= self::MAX_PAGES; ++$pageNumber) {
            try {
                $rawBody = $this->client->fetchPage(
                    clientId: $target->clientId,
                    apiKey: $target->apiKey,
                    since: $since,
                    to: $to,
                    cursor: $cursor,
                );
            } catch (\Throwable $failure) {
                if (!OzonAuthorizationFailure::isAuthorizationFailure($failure)) {
                    throw $failure;
                }

                $this->brokenLogger->log($message->companyId, $message->marketplaceAccountId, 'sales', $failure, $target->apiKey);
                $this->identityFacade->markOzonAccountBroken($message->companyId, $message->marketplaceAccountId);
                $this->dayLoads->mark($message->companyId, $message->marketplaceAccountId, [DayLoadMark::failed(self::REPORT_TYPE, $since, $since, 'Подключение отозвано: '.$failure->getMessage())], new \DateTimeImmutable());

                return;
            }

            // id реально сохранённой строки, не обязательно id нового
            // документа (ADR-006: конфликт по идентичному контенту сохраняет
            // более раннюю запись) — на него ссылаются факты ниже.
            $pages[] = $this->rawDocuments->add(MarketplaceRawDocument::capture(
                companyId: $companyId,
                marketplaceAccountId: $marketplaceAccountId,
                reportType: self::REPORT_TYPE,
                period: $since,
                rawBody: $rawBody,
            ));
            $lock->refresh();

            $page = OzonPostingPage::fromBody($rawBody);
            if (!$page->hasNext) {
                $this->recordDay($target, $companyId, $marketplaceAccountId, $pages);
                $this->dayLoads->mark($message->companyId, $message->marketplaceAccountId, [DayLoadMark::completed(self::REPORT_TYPE, $since, $since)], new \DateTimeImmutable());

                return;
            }

            if (isset($seenCursors[$page->cursor])) {
                throw new \RuntimeException('Ozon postings cursor repeated — pagination does not advance.');
            }
            $seenCursors[$page->cursor] = true;
            $cursor = $page->cursor;
        }

        throw new \RuntimeException(\sprintf('Продажи за %s не уместились в %d страниц; выгрузка неполна.', $message->businessDate, self::MAX_PAGES));
    }

    /**
     * Raw страниц перечитывается по одной: факты дня не копятся в памяти
     * целиком, сколько бы страниц ни было.
     *
     * @param list<Uuid> $pages
     */
    private function recordDay(OzonSyncTarget $target, Uuid $companyId, Uuid $marketplaceAccountId, array $pages): void
    {
        $observedAt = new \DateTimeImmutable();

        $this->connection->transactional(function () use ($target, $companyId, $marketplaceAccountId, $pages, $observedAt): void {
            foreach ($pages as $rawDocumentId) {
                $rawBody = $this->rawDocuments->body($target->companyId, $marketplaceAccountId, $rawDocumentId);
                $statuses = $this->statusParser->parse($rawBody, $companyId, $marketplaceAccountId, $rawDocumentId, $observedAt);
                $facts = $this->parser->parse($rawBody, $companyId, $marketplaceAccountId, $rawDocumentId);
                $this->postingStatuses->recordChanged($target->companyId, $statuses);
                $this->salesFacts->upsertAll($facts);
            }
        });
    }
}
