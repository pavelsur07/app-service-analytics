<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Кампания из `GET /api/client/campaign` — то, что нужно пробе рекламного
 * ключа (ADR-026, п. 1). Справочник кампаний с бюджетами заводится
 * вместе с его таблицей, не раньше.
 */
final readonly class OzonAdCampaign
{
    public const string StateArchived = 'CAMPAIGN_STATE_ARCHIVED';

    /** Оплата за клик — у таких кампаний есть список товаров. */
    public const string TypeSku = 'SKU';

    public function __construct(
        public string $id,
        public string $state,
        public string $advObjectType,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
