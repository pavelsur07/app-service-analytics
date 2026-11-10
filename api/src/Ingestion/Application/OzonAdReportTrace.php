<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Ingestion\Application\Message\CheckOzonAdSkuReportMessage;
use App\Ingestion\Application\Message\OrderOzonAdSkuReportMessage;
use Symfony\Component\Uid\Uuid;

/**
 * Контекст доставки. accepted связывает order_ref с report_uuid, поэтому
 * формат сериализованных сообщений не меняется (включая откат воркеров).
 */
final readonly class OzonAdReportTrace
{
    public string $invocationId;
    private ?string $orderRef;

    public function __construct(
        private OrderOzonAdSkuReportMessage|CheckOzonAdSkuReportMessage $message,
        string $hmacKey,
    ) {
        $this->invocationId = Uuid::v7()->toRfc4122();
        if ($message instanceof OrderOzonAdSkuReportMessage) {
            $campaigns = $message->campaignIds;
            sort($campaigns, \SORT_STRING);
            // Serialize не зависит от кодировки входа; на выходе только HMAC.
            $this->orderRef = hash_hmac('sha256', "ozon-report-order:v1\0".serialize([
                $message->companyId, $message->marketplaceAccountId,
                $message->kind ?? 'sku', $message->from, $message->to, $campaigns,
            ]), $hmacKey);
        } else {
            $this->orderRef = null;
        }
    }

    public function companyId(): string
    {
        return self::uuid($this->message->companyId) ?? '';
    }

    /** @return array<string, int|string|null> */
    public function context(): array
    {
        return [
            'schema_version' => 1,
            'api' => 'performance',
            'company_id' => self::uuid($this->message->companyId),
            'marketplace_account_id' => self::uuid($this->message->marketplaceAccountId),
            'order_ref' => $this->orderRef,
            'invocation_id' => $this->invocationId,
            'phase' => $this->message instanceof OrderOzonAdSkuReportMessage ? 'order' : 'check',
            'kind' => match ($this->message->kind ?? null) {
                null, 'sku' => 'sku',
                'cpo_orders' => 'cpo_orders',
                default => 'unknown',
            },
            'attempt' => $this->message->attempt,
            'report_uuid' => $this->message instanceof CheckOzonAdSkuReportMessage ? self::uuid($this->message->uuid) : null,
        ];
    }

    public static function uuid(?string $value): ?string
    {
        return null !== $value && 1 === preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $value) ? $value : null;
    }
}
