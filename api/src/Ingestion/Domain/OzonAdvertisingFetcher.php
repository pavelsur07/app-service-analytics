<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Рекламный API Ozon (ADR-026). Тела ответов — как есть, без разбора:
 * raw-слой хранит точные байты (ADR-006).
 *
 * Токен — отдельным вызовом, а не внутри каждого метода: он живёт
 * 30 минут, и сценарий из нескольких запросов берёт его один раз.
 */
interface OzonAdvertisingFetcher
{
    public function token(string $clientId, string $clientSecret): string;

    public function campaigns(string $token): string;

    public function campaignProducts(string $token, string $campaignId): string;
}
