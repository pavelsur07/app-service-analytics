<?php

declare(strict_types=1);

namespace App\Ingestion\Application\MessageHandler;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Application\Message\CheckOzonAdSkuReportMessage;
use App\Ingestion\Application\Message\OrderOzonAdSkuReportMessage;
use App\Ingestion\Application\OzonAccountBrokenLogger;
use App\Ingestion\Application\OzonAdvertisingWindows;
use App\Ingestion\Domain\OzonAdReportKind;
use App\Ingestion\Domain\OzonAdvertisingFetcher;
use App\Ingestion\Domain\OzonAuthorizationFailure;
use App\Ingestion\Domain\OzonRateLimited;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Заказ асинхронного отчёта рекламы — SKU-отчёта или отчёта заказов
 * «Оплаты за заказ» (ADR-026 п. 4): заказать и поставить
 * проверку с UUID через 30 секунд. Ждать готовности здесь нельзя —
 * это минута занятого воркера на каждый отчёт.
 *
 * Из ответа читается только UUID. Отказ лимита (429) — в том числе
 * «у кабинета уже формируется отчёт» — повторяется примерно через минуту
 * с разбросом, не дольше суток; прочий отказ 4xx — предупреждение в журнал. Повторная доставка
 * сообщения закажет отчёт ещё раз: это согласованное отступление
 * от CLAUDE.md §4 (ADR-026 п. 4), лишняя выгрузка из суточного лимита.
 */
#[AsMessageHandler]
final readonly class OrderOzonAdSkuReportHandler
{
    public const int FIRST_CHECK_DELAY_MS = 30_000;

    public const int RATE_LIMIT_RETRY_MS = 60_000;

    /**
     * Сколько повторять заказ на отказах лимита — по времени от первого
     * отказа, а не числом попыток: задержка случайна, и число попыток
     * не равно времени. Потолок меряет длительность отказа, а не место
     * в очереди: площадка пропускает примерно один заказ в минуту,
     * и первичная загрузка с сотней заказов проходит за часы. Сутки — это
     * и сброс суточного лимита выгрузок.
     */
    public const string GIVE_UP_AFTER = 'PT24H';

    /** Разброс задержки повтора: отклонённые заказы не просыпаются одной волной. */
    private const int RETRY_JITTER_MS = 30_000;

    public function __construct(
        private IdentityFacade $identityFacade,
        private OzonAccountBrokenLogger $brokenLogger,
        private OzonAdvertisingFetcher $client,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(OrderOzonAdSkuReportMessage $message): void
    {
        $timezone = new \DateTimeZone(OzonAdvertisingWindows::TIMEZONE);
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $message->from, $timezone);
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $message->to, $timezone);
        $kind = $message->reportKind();
        $campaignsValid = OzonAdReportKind::Sku === $kind
            ? [] !== $message->campaignIds && \count($message->campaignIds) <= OzonAdvertisingWindows::SKU_CAMPAIGNS_PER_REQUEST
            : [] === $message->campaignIds;
        if (false === $from || false === $to || $to < $from || !$campaignsValid) {
            throw new \InvalidArgumentException('Ozon advertising report order needs valid dates; SKU reports 1..10 campaigns, orders reports none.');
        }

        $target = $this->identityFacade->findOzonAdvertisingTarget($message->companyId, $message->marketplaceAccountId);
        if (null === $target) {
            $this->logger->info('Реклама подключения не активна — заказ отчёта пропущен', [
                'company_id' => $message->companyId,
                'marketplace_account_id' => $message->marketplaceAccountId,
            ]);

            return;
        }

        try {
            $token = $this->client->token($target->performanceClientId, $target->performanceClientSecret);
            $body = OzonAdReportKind::Sku === $kind
                ? $this->client->orderSkuReport($token, $message->campaignIds, $from, $to)
                : $this->client->orderCpoOrdersReport($token, $from, $to);
        } catch (\Throwable $failure) {
            if (OzonAuthorizationFailure::isAuthorizationFailure($failure)) {
                $this->brokenLogger->log($target->companyId, $target->marketplaceAccountId, 'advertising', $failure, $target->performanceClientSecret);
                $this->identityFacade->markOzonAdvertisingBroken($target->companyId, $target->marketplaceAccountId, $target->version);

                return;
            }
            if (OzonRateLimited::is($failure)) {
                $this->retryLater($message);

                return;
            }
            $status = OzonRateLimited::clientErrorStatus($failure);
            if (null !== $status) {
                // Отказ площадки 4xx — заказ точно не состоялся, и повтор
                // того же запроса ответ не изменит (например, период
                // глубже истории отчёта).
                $this->giveUp($message, "площадка отклонила заказ: HTTP {$status}");

                return;
            }

            throw $failure;
        }

        $this->bus->dispatch(
            new CheckOzonAdSkuReportMessage($message->companyId, $message->marketplaceAccountId, $message->from, self::uuid($body), 1, $kind, $message->to),
            [new DelayStamp(self::FIRST_CHECK_DELAY_MS)],
        );
    }

    /**
     * Отказ лимита — в том числе «у кабинета уже формируется отчёт» —
     * повторяется через минуту новым сообщением, а не исключением очереди:
     * у повтора есть потолок, и постоянный отказ (исчерпан суточный лимит)
     * заканчивается предупреждением, а не бесконечной петлёй.
     */
    private function retryLater(OrderOzonAdSkuReportMessage $message): void
    {
        $now = new \DateTimeImmutable();
        $refusedSince = null === $message->refusedSince ? $now : new \DateTimeImmutable($message->refusedSince);
        if ($refusedSince->add(new \DateInterval(self::GIVE_UP_AFTER)) <= $now) {
            $this->giveUp($message, 'отказ лимита площадки (429) дольше суток');

            return;
        }

        $this->bus->dispatch(
            new OrderOzonAdSkuReportMessage(
                $message->companyId,
                $message->marketplaceAccountId,
                $message->from,
                $message->to,
                $message->campaignIds,
                $message->attempt + 1,
                $refusedSince->format(\DateTimeInterface::ATOM),
                $message->reportKind(),
            ),
            [new DelayStamp(self::RATE_LIMIT_RETRY_MS - self::RETRY_JITTER_MS + random_int(0, 2 * self::RETRY_JITTER_MS))],
        );
    }

    /**
     * Сигнал, а не тишина: незаказанный отчёт виден в журнале уровнем
     * `warning` (порог prod-журнала).
     */
    private function giveUp(OrderOzonAdSkuReportMessage $message, string $reason): void
    {
        $this->logger->warning('Отчёт рекламы Ozon не заказан', [
            'kind' => $message->reportKind(),
            'company_id' => $message->companyId,
            'marketplace_account_id' => $message->marketplaceAccountId,
            'period_from' => $message->from,
            'period_to' => $message->to,
            'attempt' => $message->attempt,
            'reason' => $reason,
        ]);
    }

    private static function uuid(string $body): string
    {
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        $uuid = \is_array($decoded) ? ($decoded['UUID'] ?? null) : null;
        if (!\is_string($uuid) || 1 !== preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $uuid)) {
            throw new \UnexpectedValueException('Ozon SKU report order: no UUID in the response.');
        }

        return $uuid;
    }
}
