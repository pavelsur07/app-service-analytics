<?php

declare(strict_types=1);

namespace App\Ingestion\Application\MessageHandler;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Application\Message\FetchOzonAdCampaignStatsMessage;
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
 * Кусок рекламы в raw-слой: ответы `expense` (расход кампаний за день)
 * и `daily` (статистика кампаний за день) за один период сохраняются
 * как есть, каждый своим типом raw-документа (ADR-026 п. 3).
 *
 * Отказ авторизации переводит в broken только рекламу
 * (`markOzonAdvertisingBroken`), подключение продолжает грузить продажи
 * и расходы (ADR-026 п. 1). Реклама, отключившаяся после постановки
 * сообщения, — не ошибка: сообщение завершается без загрузки.
 *
 * Повтор идемпотентен: raw дедуплицируется по содержимому (ADR-006).
 */
#[AsMessageHandler]
final readonly class FetchOzonAdCampaignStatsHandler
{
    public function __construct(
        private IdentityFacade $identityFacade,
        private OzonAccountBrokenLogger $brokenLogger,
        private OzonAdvertisingFetcher $client,
        private MarketplaceRawDocumentRepository $rawDocuments,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FetchOzonAdCampaignStatsMessage $message): void
    {
        [$from, $to] = self::period($message);

        $target = $this->identityFacade->findOzonAdvertisingTarget($message->companyId, $message->marketplaceAccountId);
        if (null === $target) {
            $this->logger->info('Реклама подключения не активна — загрузка статистики пропущена', [
                'company_id' => $message->companyId,
                'marketplace_account_id' => $message->marketplaceAccountId,
            ]);

            return;
        }

        $companyId = Uuid::fromString($target->companyId);
        $accountId = Uuid::fromString($target->marketplaceAccountId);

        try {
            $token = $this->client->token($target->performanceClientId, $target->performanceClientSecret);
            $this->capture($companyId, $accountId, MarketplaceReportType::OzonAdExpense, $from, $this->client->expense($token, $from, $to));
            $this->capture($companyId, $accountId, MarketplaceReportType::OzonAdDaily, $from, $this->client->daily($token, $from, $to));
        } catch (\Throwable $failure) {
            if (!OzonAuthorizationFailure::isAuthorizationFailure($failure)) {
                throw $failure;
            }

            $this->brokenLogger->log($target->companyId, $target->marketplaceAccountId, 'advertising', $failure, $target->performanceClientSecret);
            $this->identityFacade->markOzonAdvertisingBroken($target->companyId, $target->marketplaceAccountId);
        }
    }

    private function capture(Uuid $companyId, Uuid $accountId, string $reportType, \DateTimeImmutable $period, string $body): void
    {
        $this->rawDocuments->add(MarketplaceRawDocument::capture(
            companyId: $companyId,
            marketplaceAccountId: $accountId,
            reportType: $reportType,
            period: $period,
            rawBody: $body,
        ));
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private static function period(FetchOzonAdCampaignStatsMessage $message): array
    {
        $timezone = new \DateTimeZone(OzonAdvertisingWindows::TIMEZONE);
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $message->from, $timezone);
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $message->to, $timezone);
        if (false === $from || false === $to
            || $from->format('Y-m-d') !== $message->from || $to->format('Y-m-d') !== $message->to
            || $to < $from) {
            throw new \InvalidArgumentException('Ozon advertising chunk must be valid inclusive Y-m-d dates.');
        }

        $days = (int) $from->diff($to)->days + 1;
        if ($days > OzonAdvertisingWindows::CHUNK_DAYS) {
            throw new \InvalidArgumentException("Ozon advertising chunk is {$days} days, longer than ".OzonAdvertisingWindows::CHUNK_DAYS.'.');
        }

        return [$from, $to];
    }
}
