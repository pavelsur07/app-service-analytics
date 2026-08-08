<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Интерфейс в Domain, реализация (HTTP, symfony/http-client) —
 * в Infrastructure/Connector/Ozon\OzonPostingFboListClient. Application
 * зависит от этого интерфейса, не от конкретного клиента — тот же приём,
 * что MarketplaceCredentialsEncryptor в Identity (пакет 1).
 */
interface OzonPostingsFetcher
{
    /**
     * Возвращает тело ответа как есть, без разбора (см. OzonPostingFboListClient).
     */
    public function fetch(
        string $clientId,
        string $apiKey,
        \DateTimeImmutable $since,
        \DateTimeImmutable $to,
    ): string;
}
