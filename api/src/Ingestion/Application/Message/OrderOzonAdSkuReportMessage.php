<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Message;

/**
 * Заказать асинхронный SKU-отчёт за `[from, to]` (`Y-m-d` по Москве)
 * по пачке кампаний не больше десяти (ADR-026 п. 4).
 */
final readonly class OrderOzonAdSkuReportMessage
{
    /**
     * @param list<string> $campaignIds
     */
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
        public string $from,
        public string $to,
        public array $campaignIds,
    ) {
    }
}
