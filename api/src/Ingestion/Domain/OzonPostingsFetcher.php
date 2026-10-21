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
     * Потолок страницы `/v3/posting/fbo/list` (ADR-042). День листается
     * курсором, поэтому полная страница — не признак потери, а повод
     * спросить следующую: решает `has_next` ответа (`OzonPostingPage`).
     */
    public const int PAGE_LIMIT = 100;

    /**
     * Одна страница выгрузки; тело ответа как есть, без разбора
     * (см. OzonPostingFboListClient). Пустой курсор — первая страница.
     */
    public function fetchPage(
        string $clientId,
        string $apiKey,
        \DateTimeImmutable $since,
        \DateTimeImmutable $to,
        string $cursor,
    ): string;
}
