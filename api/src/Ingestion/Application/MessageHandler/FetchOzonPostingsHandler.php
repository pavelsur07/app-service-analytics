<?php

declare(strict_types=1);

namespace App\Ingestion\Application\MessageHandler;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\OzonAuthorizationFailure;
use App\Ingestion\Domain\OzonPostingFboListParser;
use App\Ingestion\Domain\OzonPostingsFetcher;
use App\Ingestion\Domain\OzonPostingStatusParser;
use App\Ingestion\Domain\SalesFactRepository;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Клиент (пакет 2) -> raw (пакет 3) -> парсер (пакет 4) -> upsert facts.
 * Идемпотентен целиком: raw дедуплицируется по естественному ключу
 * (ADR-006), sales_fact — upsert по своему (ADR-006); повторный запуск
 * на тех же входных данных не меняет результат.
 */
#[AsMessageHandler]
final readonly class FetchOzonPostingsHandler
{
    private const int LOCK_TTL_SECONDS = 900;

    private const string REPORT_TYPE = MarketplaceReportType::OzonPostingFboList;

    // Europe/Moscow — константа коннектора Ozon, не настройка подключения
    // (ADR-009): у площадки нет пользовательских часовых поясов кабинета.
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private LockFactory $lockFactory,
        private IdentityFacade $identityFacade,
        private OzonPostingsFetcher $client,
        private OzonPostingFboListParser $parser,
        private OzonPostingStatusParser $statusParser,
        private MarketplaceRawDocumentRepository $rawDocuments,
        private MarketplacePostingStatusRepository $postingStatuses,
        private SalesFactRepository $salesFacts,
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
            $this->sync($message);
        } finally {
            $lock->release();
        }
    }

    private function sync(FetchOzonPostingsMessage $message): void
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
            $rawBody = $this->client->fetch(
                clientId: $target->clientId,
                apiKey: $target->apiKey,
                since: $since,
                to: $to,
            );
        } catch (\Throwable $failure) {
            if (!OzonAuthorizationFailure::isAuthorizationFailure($failure)) {
                throw $failure;
            }

            $this->identityFacade->markOzonAccountBroken($message->companyId, $message->marketplaceAccountId);

            return;
        }

        $companyId = Uuid::fromString($target->companyId);
        $marketplaceAccountId = Uuid::fromString($target->marketplaceAccountId);

        $rawDocument = MarketplaceRawDocument::capture(
            companyId: $companyId,
            marketplaceAccountId: $marketplaceAccountId,
            reportType: self::REPORT_TYPE,
            period: $since,
            rawBody: $rawBody,
        );
        // id реально сохранённой строки, не обязательно $rawDocument->id()
        // (ADR-006: конфликт по идентичному контенту сохраняет более
        // раннюю запись) — на него ссылаются факты ниже.
        $rawDocumentId = $this->rawDocuments->add($rawDocument);

        $this->refuseSilentTruncation($rawBody, $message->businessDate);

        $observedAt = new \DateTimeImmutable();
        $statuses = $this->statusParser->parse(
            $rawBody,
            $companyId,
            $marketplaceAccountId,
            $rawDocumentId,
            $observedAt,
        );
        $facts = $this->parser->parse($rawBody, $companyId, $marketplaceAccountId, $rawDocumentId);
        $this->postingStatuses->recordChanged($target->companyId, $statuses);
        $this->salesFacts->upsertAll($facts);
    }

    /**
     * Ответ ровно в потолок — признак того, что день в него не поместился.
     *
     * Запрос страниц не листает: у продавца дни по сотне отправлений,
     * до тысячи далеко, и пагинация ради ненаступившего случая была бы
     * кодом без потребителя. Но потолок обязан быть громким: молча
     * отдать девятьсот девяносто девять из полутора тысяч — это отчёт,
     * который выглядит полным и врёт. Упереться в него — повод завести
     * пагинацию, а не увеличить число.
     */
    private function refuseSilentTruncation(string $rawBody, string $businessDate): void
    {
        $decoded = json_decode($rawBody, true);
        if (!\is_array($decoded) || !isset($decoded['result']) || !\is_array($decoded['result'])) {
            // Разбор ответа — забота парсера, он же и объяснит, что не так.
            return;
        }

        if (\count($decoded['result']) >= OzonPostingsFetcher::MAX_LIMIT) {
            throw new \RuntimeException(\sprintf('Ozon вернул %d отправлений за %s — это потолок запроса. День в одну страницу не помещается, нужна пагинация.', \count($decoded['result']), $businessDate));
        }
    }
}
