<?php

declare(strict_types=1);

namespace App\Ingestion\Application\MessageHandler;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\OzonPostingFboListParser;
use App\Ingestion\Domain\OzonPostingsFetcher;
use App\Ingestion\Domain\SalesFactRepository;
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
    private const string REPORT_TYPE = 'ozon_posting_fbo_list';

    // Europe/Moscow — константа коннектора Ozon, не настройка подключения
    // (ADR-009): у площадки нет пользовательских часовых поясов кабинета.
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private IdentityFacade $identityFacade,
        private OzonPostingsFetcher $client,
        private OzonPostingFboListParser $parser,
        private MarketplaceRawDocumentRepository $rawDocuments,
        private SalesFactRepository $salesFacts,
    ) {
    }

    public function __invoke(FetchOzonPostingsMessage $message): void
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

        $rawBody = $this->client->fetch(
            clientId: $target->clientId,
            apiKey: $target->apiKey,
            since: $since,
            to: $to,
        );

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

        $facts = $this->parser->parse($rawBody, $companyId, $marketplaceAccountId, $rawDocumentId);
        $this->salesFacts->upsertAll($facts);
    }
}
