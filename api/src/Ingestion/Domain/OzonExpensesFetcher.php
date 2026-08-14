<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Интерфейс в Domain, реализация (HTTP) — Infrastructure/Connector/Ozon\
 * OzonAccrualByDayClient. Тот же приём, что у OzonPostingsFetcher
 * и OzonCatalogFetcher.
 */
interface OzonExpensesFetcher
{
    /**
     * Начисления за один день. День, а не диапазон: метод отвечает
     * отказом на диапазон («Date: value length must be 10 runes»),
     * и это не наше упрощение, а его контракт (ADR-012).
     *
     * $lastId — курсор внутри дня, пустая строка означает первую страницу.
     *
     * Возвращает тело ответа как есть, без разбора.
     */
    public function fetchDay(string $clientId, string $apiKey, \DateTimeImmutable $day, string $lastId): string;
}
