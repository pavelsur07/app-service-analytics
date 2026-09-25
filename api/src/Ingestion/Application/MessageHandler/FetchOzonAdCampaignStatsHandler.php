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
use App\Ingestion\Domain\OzonAdCampaign;
use App\Ingestion\Domain\OzonAdCampaignListParser;
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
 * Головной кусок (кончается в последние 30 дней) после них сохраняет
 * список кампаний — это единственная его загрузка на тике. Если в кусок
 * попадают вчера или сегодня, по этому списку снимается `products/sku`
 * за каждый из этих дней (ADR-026 п. 4): другого
 * синхронного способа получить SKU-разбивку свежих дней нет. Кампании —
 * все неархивные типа `SKU` из списка кампаний, пачками не больше десяти;
 * у других типов списка товаров нет. Нет таких кампаний — запроса нет:
 * пустой список площадка отклоняет.
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
        private OzonAdCampaignListParser $campaignParser,
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

            $today = OzonAdvertisingWindows::today(new \DateTimeImmutable());
            if (OzonAdvertisingWindows::isHeadChunk($to, $today)) {
                $this->captureCampaignsAndSku($companyId, $accountId, $token, $to, OzonAdvertisingWindows::skuDays($from, $to, $today));
            }
        } catch (\Throwable $failure) {
            if (!OzonAuthorizationFailure::isAuthorizationFailure($failure)) {
                throw $failure;
            }

            $this->brokenLogger->log($target->companyId, $target->marketplaceAccountId, 'advertising', $failure, $target->performanceClientSecret);
            $this->identityFacade->markOzonAdvertisingBroken($target->companyId, $target->marketplaceAccountId, $target->version);
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
     * @param list<\DateTimeImmutable> $days
     */
    private function captureCampaignsAndSku(Uuid $companyId, Uuid $accountId, string $token, \DateTimeImmutable $to, array $days): void
    {
        // Список кампаний снимает только головной кусок — один запрос
        // метода на тик: второй одновременный упирался в лимит площадки (429).
        // Сначала raw, потом разбор (ADR-006). `period` — последний день
        // куска из сообщения, а не часы обработчика: повтор после полуночи
        // попадает в тот же документ.
        $campaigns = $this->client->campaigns($token);
        $this->capture($companyId, $accountId, MarketplaceReportType::OzonAdCampaigns, $to, $campaigns);
        if ([] === $days) {
            return;
        }

        $campaignIds = array_values(array_map(
            static fn (OzonAdCampaign $campaign): string => $campaign->id,
            array_filter(
                $this->campaignParser->parse($campaigns),
                static fn (OzonAdCampaign $campaign): bool => OzonAdCampaign::StateArchived !== $campaign->state
                    && OzonAdCampaign::TypeSku === $campaign->advObjectType,
            ),
        ));

        foreach ($days as $day) {
            foreach (array_chunk($campaignIds, OzonAdvertisingWindows::SKU_CAMPAIGNS_PER_REQUEST) as $batch) {
                $this->capture($companyId, $accountId, MarketplaceReportType::OzonAdSkuDay, $day, $this->client->productsSku($token, $batch, $day));
            }
        }
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
