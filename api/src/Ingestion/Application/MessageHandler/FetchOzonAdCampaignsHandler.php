<?php

declare(strict_types=1);

namespace App\Ingestion\Application\MessageHandler;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Application\Message\FetchOzonAdCampaignsMessage;
use App\Ingestion\Application\OzonAccountBrokenLogger;
use App\Ingestion\Application\OzonAdvertisingWindows;
use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\OzonAdvertisingFetcher;
use App\Ingestion\Domain\OzonAuthorizationFailure;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Список рекламных кампаний в raw-слой как есть (ADR-026 п. 3). `period` —
 * день снимка из сообщения: его задаёт отправитель, и повтор сообщения
 * после полуночи попадает в тот же документ. Отказ авторизации — broken
 * только у рекламы (ADR-026 п. 1).
 */
#[AsMessageHandler]
final readonly class FetchOzonAdCampaignsHandler
{
    public function __construct(
        private IdentityFacade $identityFacade,
        private OzonAccountBrokenLogger $brokenLogger,
        private OzonAdvertisingFetcher $client,
        private MarketplaceRawDocumentRepository $rawDocuments,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FetchOzonAdCampaignsMessage $message): void
    {
        $target = $this->identityFacade->findOzonAdvertisingTarget($message->companyId, $message->marketplaceAccountId);
        if (null === $target) {
            $this->logger->info('Реклама подключения не активна — загрузка кампаний пропущена', [
                'company_id' => $message->companyId,
                'marketplace_account_id' => $message->marketplaceAccountId,
            ]);

            return;
        }

        try {
            $token = $this->client->token($target->performanceClientId, $target->performanceClientSecret);
            $body = $this->client->campaigns($token);
        } catch (\Throwable $failure) {
            if (!OzonAuthorizationFailure::isAuthorizationFailure($failure)) {
                throw $failure;
            }

            $this->brokenLogger->log($target->companyId, $target->marketplaceAccountId, 'advertising', $failure, $target->performanceClientSecret);
            $this->identityFacade->markOzonAdvertisingBroken($target->companyId, $target->marketplaceAccountId, $target->version);

            return;
        }

        $this->rawDocuments->add(MarketplaceRawDocument::capture(
            companyId: Uuid::fromString($target->companyId),
            marketplaceAccountId: Uuid::fromString($target->marketplaceAccountId),
            reportType: MarketplaceReportType::OzonAdCampaigns,
            period: self::day($message),
            rawBody: $body,
        ));
    }

    private static function day(FetchOzonAdCampaignsMessage $message): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $message->day, new \DateTimeZone(OzonAdvertisingWindows::TIMEZONE));
        if (false === $day || $day->format('Y-m-d') !== $message->day) {
            throw new \InvalidArgumentException('Ozon advertising campaigns day must be a valid Y-m-d date.');
        }

        return $day;
    }
}
