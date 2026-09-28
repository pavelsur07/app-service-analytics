<?php

declare(strict_types=1);

namespace App\Ingestion\Application\MessageHandler;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Application\Message\FetchOzonAdCampaignStatsMessage;
use App\Ingestion\Application\Message\OrderOzonAdSkuReportMessage;
use App\Ingestion\Application\OzonAccountBrokenLogger;
use App\Ingestion\Application\OzonAdvertisingWindows;
use App\Ingestion\Application\StoreOzonAdSkuExpenses;
use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\OzonAdCampaign;
use App\Ingestion\Domain\OzonAdCampaignListParser;
use App\Ingestion\Domain\OzonAdExpenseParser;
use App\Ingestion\Domain\OzonAdReportKind;
use App\Ingestion\Domain\OzonAdvertisingFetcher;
use App\Ingestion\Domain\OzonAuthorizationFailure;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
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
 * синхронного способа получить SKU-разбивку свежих дней нет. Ответ
 * после сохранения в raw разбирается в факты рекламы по SKU (ADR-035 п. 3).
 *
 * Кампании SKU-разбивки — те, у кого в только что загруженном `expense`
 * есть расход в запрашиваемом периоде, типа `SKU` (архивные включительно)
 * или отсутствующие в списке кампаний (ADR-035 п. 4); пачками не больше
 * десяти. У других типов списка товаров нет. Нет таких кампаний — запроса
 * нет: пустой список площадка отклоняет.
 *
 * Неудача разбора — `products/sku` или `expense` для отбора — не обрывает
 * остальные выгрузки куска: они доделываются, и только после этого
 * обработчик бросает неповторяемое исключение с первой неудачей. Так
 * она попадает в трекер (ADR-006), а сообщение — в failed-транспорт
 * без повторов: повтор получил бы тот же ответ и заказал бы отчёты
 * ещё раз.
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
        private OzonAdExpenseParser $expenseParser,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        private StoreOzonAdSkuExpenses $skuExpenses,
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
            $expense = $this->client->expense($token, $from, $to);
            $this->capture($companyId, $accountId, MarketplaceReportType::OzonAdExpense, $from, $expense);
            $this->capture($companyId, $accountId, MarketplaceReportType::OzonAdDaily, $from, $this->client->daily($token, $from, $to));

            /** @var list<\Throwable> $parseFailures */
            $parseFailures = [];

            $today = OzonAdvertisingWindows::today(new \DateTimeImmutable());
            if (OzonAdvertisingWindows::isHeadChunk($to, $today)) {
                $parseFailures = [...$parseFailures, ...$this->captureCampaignsAndSku($companyId, $accountId, $token, $to, OzonAdvertisingWindows::skuDays($from, $to, $today), $expense)];
            }

            if (true === ($message->withReports ?? false)) {
                $reportPeriod = OzonAdvertisingWindows::skuReportPeriod($from, $to, $today);
                if (null !== $reportPeriod) {
                    $parseFailures = [...$parseFailures, ...$this->orderSkuReports($message, $companyId, $accountId, $token, $reportPeriod[0], $reportPeriod[1], $expense)];
                }

                // Заказы «Оплаты за заказ» — по всей организации, за весь
                // кусок: products/sku для них нет, и вчера с сегодня никто
                // другой не отдаст.
                $this->bus->dispatch(new OrderOzonAdSkuReportMessage(
                    $message->companyId,
                    $message->marketplaceAccountId,
                    $message->from,
                    $message->to,
                    [],
                    kind: OzonAdReportKind::CpoOrders,
                ));
            }

            if ([] !== $parseFailures) {
                throw new UnrecoverableMessageHandlingException(\sprintf('Реклама Ozon: не разобрано ответов — %d, подключение %s, кусок %s..%s: %s', \count($parseFailures), $message->marketplaceAccountId, $message->from, $message->to, $parseFailures[0]->getMessage()), 0, $parseFailures[0]);
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
     * `products/sku` за день: сначала raw, потом разбор в факты (ADR-006).
     * Неудача разбора возвращается, а не бросается: остальные выгрузки
     * куска доделываются.
     */
    private function captureSkuDay(Uuid $companyId, Uuid $accountId, \DateTimeImmutable $day, string $body): ?\Throwable
    {
        $captured = MarketplaceRawDocument::capture(
            companyId: $companyId,
            marketplaceAccountId: $accountId,
            reportType: MarketplaceReportType::OzonAdSkuDay,
            period: $day,
            rawBody: $body,
        );

        $rawDocumentId = $this->rawDocuments->add($captured);
        try {
            $this->skuExpenses->fromDay($captured, $rawDocumentId);
        } catch (\UnexpectedValueException|\JsonException $failure) {
            return $failure;
        }

        return null;
    }

    /**
     * @param list<\DateTimeImmutable> $days
     *
     * @return list<\Throwable> неудачи разбора
     */
    private function captureCampaignsAndSku(Uuid $companyId, Uuid $accountId, string $token, \DateTimeImmutable $to, array $days, string $expense): array
    {
        // Список кампаний снимает только головной кусок — один запрос
        // метода на тик: второй одновременный упирался в лимит площадки (429).
        // Сначала raw, потом разбор (ADR-006). `period` — последний день
        // куска из сообщения, а не часы обработчика: повтор после полуночи
        // попадает в тот же документ.
        $campaigns = $this->client->campaigns($token);
        $this->capture($companyId, $accountId, MarketplaceReportType::OzonAdCampaigns, $to, $campaigns);
        $failures = [];
        foreach ($days as $day) {
            try {
                $campaignIds = $this->spendingSkuCampaignIds($campaigns, $expense, $day, $day);
            } catch (\UnexpectedValueException|\JsonException $failure) {
                // Расход не разобран — отбирать кампании не по чему.
                return [$failure];
            }

            foreach (array_chunk($campaignIds, OzonAdvertisingWindows::SKU_CAMPAIGNS_PER_REQUEST) as $batch) {
                $failure = $this->captureSkuDay($companyId, $accountId, $day, $this->client->productsSku($token, $batch, $day));
                if (null !== $failure) {
                    $failures[] = $failure;
                }
            }
        }

        return $failures;
    }

    /**
     * SKU-отчёты за кусок без вчера и сегодня (ADR-026 п. 4) — последним
     * шагом, после сохранения расхода: отказ раньше не оставит заказанных
     * отчётов у недозагруженного куска. Кампании — с расходом в периоде
     * отчёта (ADR-035 п. 4), тип — из последнего сохранённого списка;
     * его ещё нет — список запрашивается и сохраняется, сначала raw,
     * потом разбор.
     *
     * @return list<\Throwable> неудачи разбора
     */
    private function orderSkuReports(
        FetchOzonAdCampaignStatsMessage $message,
        Uuid $companyId,
        Uuid $accountId,
        string $token,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $expense,
    ): array {
        $campaigns = $this->rawDocuments->latestBody($message->companyId, $accountId, MarketplaceReportType::OzonAdCampaigns);
        if (null === $campaigns) {
            $campaigns = $this->client->campaigns($token);
            $this->capture($companyId, $accountId, MarketplaceReportType::OzonAdCampaigns, $to, $campaigns);
        }

        try {
            $campaignIds = $this->spendingSkuCampaignIds($campaigns, $expense, $from, $to);
        } catch (\UnexpectedValueException|\JsonException $failure) {
            // Расход не разобран — отбирать кампании не по чему; заказ
            // отчёта «Оплаты за заказ» от отбора не зависит и идёт дальше.
            return [$failure];
        }

        foreach (array_chunk($campaignIds, OzonAdvertisingWindows::SKU_CAMPAIGNS_PER_REQUEST) as $batch) {
            $this->bus->dispatch(new OrderOzonAdSkuReportMessage(
                $message->companyId,
                $message->marketplaceAccountId,
                $from->format('Y-m-d'),
                $to->format('Y-m-d'),
                $batch,
            ));
        }

        return [];
    }

    /**
     * Кампании с расходом в периоде (ADR-035 п. 4), архивные включительно:
     * отбор по состоянию терял историю всех заархивированных. Из них
     * отбрасываются только кампании известного другого типа — у них списка
     * товаров нет. Кампания с расходом, которой нет в списке, остаётся:
     * лишний отчёт дешевле потерянной разбивки.
     *
     * @return list<string>
     */
    private function spendingSkuCampaignIds(string $campaignList, string $expense, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $types = [];
        foreach ($this->campaignParser->parse($campaignList) as $campaign) {
            $types[$campaign->id] = $campaign->advObjectType;
        }

        return array_values(array_filter(
            $this->expenseParser->campaignsWithSpend($expense, $from, $to),
            static fn (string $id): bool => OzonAdCampaign::TypeSku === ($types[$id] ?? OzonAdCampaign::TypeSku),
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
