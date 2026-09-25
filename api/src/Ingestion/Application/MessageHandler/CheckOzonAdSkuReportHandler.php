<?php

declare(strict_types=1);

namespace App\Ingestion\Application\MessageHandler;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Application\Message\CheckOzonAdSkuReportMessage;
use App\Ingestion\Application\OzonAccountBrokenLogger;
use App\Ingestion\Application\OzonAdvertisingWindows;
use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\OzonAdvertisingFetcher;
use App\Ingestion\Domain\OzonAuthorizationFailure;
use App\Ingestion\Domain\OzonRateLimited;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Проверка заказанного SKU-отчёта (ADR-026 п. 4). Готов — скачать
 * и сохранить в raw как есть (`ozon_ad_sku_report`, `period` — начало
 * периода). Не готов — то же сообщение снова, с растущей задержкой.
 * `ERROR` площадки или потолок проверок — предупреждение в журнал, отчёт
 * не загружен: период снова закажут суточное окно, рескан или консольная
 * команда.
 *
 * Из ответа о состоянии читается только поле `state`. Скачанный отчёт
 * не разбирается.
 */
#[AsMessageHandler]
final readonly class CheckOzonAdSkuReportHandler
{
    /** 20 проверок с растущей задержкой — около часа. */
    public const int MAX_ATTEMPTS = 20;

    private const int MAX_DELAY_MS = 300_000;

    private const array NOT_READY = ['NOT_STARTED', 'IN_PROGRESS'];

    public function __construct(
        private IdentityFacade $identityFacade,
        private OzonAccountBrokenLogger $brokenLogger,
        private OzonAdvertisingFetcher $client,
        private MarketplaceRawDocumentRepository $rawDocuments,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CheckOzonAdSkuReportMessage $message): void
    {
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $message->from, new \DateTimeZone(OzonAdvertisingWindows::TIMEZONE));
        if (false === $from || $from->format('Y-m-d') !== $message->from) {
            throw new \InvalidArgumentException('Ozon SKU report check needs a valid Y-m-d period start.');
        }

        $target = $this->identityFacade->findOzonAdvertisingTarget($message->companyId, $message->marketplaceAccountId);
        if (null === $target) {
            $this->logger->info('Реклама подключения не активна — проверка SKU-отчёта пропущена', [
                'company_id' => $message->companyId,
                'marketplace_account_id' => $message->marketplaceAccountId,
            ]);

            return;
        }

        try {
            $token = $this->client->token($target->performanceClientId, $target->performanceClientSecret);
            $state = self::state($this->client->reportState($token, $message->uuid));

            if ('OK' === $state) {
                $this->rawDocuments->add(MarketplaceRawDocument::capture(
                    companyId: Uuid::fromString($target->companyId),
                    marketplaceAccountId: Uuid::fromString($target->marketplaceAccountId),
                    reportType: MarketplaceReportType::OzonAdSkuReport,
                    period: $from,
                    rawBody: $this->client->report($token, $message->uuid),
                ));

                return;
            }
        } catch (\Throwable $failure) {
            if (OzonRateLimited::is($failure)) {
                throw new RecoverableMessageHandlingException("Ozon refused the SKU report check by rate limit for account {$message->marketplaceAccountId}.", previous: $failure, retryDelay: OrderOzonAdSkuReportHandler::RATE_LIMIT_RETRY_MS);
            }
            if (!OzonAuthorizationFailure::isAuthorizationFailure($failure)) {
                throw $failure;
            }

            $this->brokenLogger->log($target->companyId, $target->marketplaceAccountId, 'advertising', $failure, $target->performanceClientSecret);
            $this->identityFacade->markOzonAdvertisingBroken($target->companyId, $target->marketplaceAccountId, $target->version);

            return;
        }

        if (!\in_array($state, self::NOT_READY, true)) {
            $this->giveUp($message, "площадка вернула состояние {$state}");

            return;
        }

        if ($message->attempt >= self::MAX_ATTEMPTS) {
            $this->giveUp($message, 'исчерпан потолок проверок');

            return;
        }

        $this->bus->dispatch(
            new CheckOzonAdSkuReportMessage($message->companyId, $message->marketplaceAccountId, $message->from, $message->uuid, $message->attempt + 1),
            [new DelayStamp(min(OrderOzonAdSkuReportHandler::FIRST_CHECK_DELAY_MS * $message->attempt, self::MAX_DELAY_MS))],
        );
    }

    /**
     * Сигнал, а не тишина: незагруженный отчёт виден в журнале уровнем
     * `warning` (порог prod-журнала).
     */
    private function giveUp(CheckOzonAdSkuReportMessage $message, string $reason): void
    {
        $this->logger->warning('SKU-отчёт рекламы Ozon не загружен', [
            'company_id' => $message->companyId,
            'marketplace_account_id' => $message->marketplaceAccountId,
            'period_from' => $message->from,
            'report_uuid' => $message->uuid,
            'attempt' => $message->attempt,
            'reason' => $reason,
        ]);
    }

    private static function state(string $body): string
    {
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        $state = \is_array($decoded) ? ($decoded['state'] ?? null) : null;
        if (!\is_string($state) || '' === $state) {
            throw new \UnexpectedValueException('Ozon SKU report state: no "state" in the response.');
        }

        return $state;
    }
}
