<?php

declare(strict_types=1);

namespace App\Ingestion\Application\MessageHandler;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Application\Message\CheckOzonAdSkuReportMessage;
use App\Ingestion\Application\Message\OrderOzonAdSkuReportMessage;
use App\Ingestion\Application\OzonAccountBrokenLogger;
use App\Ingestion\Application\OzonAdvertisingWindows;
use App\Ingestion\Domain\OzonAdvertisingFetcher;
use App\Ingestion\Domain\OzonAuthorizationFailure;
use App\Ingestion\Domain\OzonRateLimited;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Заказ асинхронного SKU-отчёта (ADR-026 п. 4): заказать и поставить
 * проверку с UUID через 30 секунд. Ждать готовности здесь нельзя —
 * это минута занятого воркера на каждый отчёт.
 *
 * Из ответа читается только UUID. Отказ лимита (429) — в том числе
 * «у кабинета уже формируется отчёт» — повторяется через минуту и попыток
 * очереди не расходует. Повторная доставка сообщения закажет отчёт ещё
 * раз: это цена схемы без таблиц, лишняя выгрузка из суточного лимита.
 */
#[AsMessageHandler]
final readonly class OrderOzonAdSkuReportHandler
{
    public const int FIRST_CHECK_DELAY_MS = 30_000;

    public const int RATE_LIMIT_RETRY_MS = 60_000;

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
        if (false === $from || false === $to || $to < $from
            || [] === $message->campaignIds
            || \count($message->campaignIds) > OzonAdvertisingWindows::SKU_CAMPAIGNS_PER_REQUEST) {
            throw new \InvalidArgumentException('Ozon SKU report order needs valid dates and 1..10 campaigns.');
        }

        $target = $this->identityFacade->findOzonAdvertisingTarget($message->companyId, $message->marketplaceAccountId);
        if (null === $target) {
            $this->logger->info('Реклама подключения не активна — заказ SKU-отчёта пропущен', [
                'company_id' => $message->companyId,
                'marketplace_account_id' => $message->marketplaceAccountId,
            ]);

            return;
        }

        try {
            $token = $this->client->token($target->performanceClientId, $target->performanceClientSecret);
            $body = $this->client->orderSkuReport($token, $message->campaignIds, $from, $to);
        } catch (\Throwable $failure) {
            if (OzonRateLimited::is($failure)) {
                throw new RecoverableMessageHandlingException("Ozon refused the SKU report order by rate limit for account {$message->marketplaceAccountId}.", previous: $failure, retryDelay: self::RATE_LIMIT_RETRY_MS);
            }
            if (!OzonAuthorizationFailure::isAuthorizationFailure($failure)) {
                throw $failure;
            }

            $this->brokenLogger->log($target->companyId, $target->marketplaceAccountId, 'advertising', $failure, $target->performanceClientSecret);
            $this->identityFacade->markOzonAdvertisingBroken($target->companyId, $target->marketplaceAccountId, $target->version);

            return;
        }

        $this->bus->dispatch(
            new CheckOzonAdSkuReportMessage($message->companyId, $message->marketplaceAccountId, $message->from, self::uuid($body), 1),
            [new DelayStamp(self::FIRST_CHECK_DELAY_MS)],
        );
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
