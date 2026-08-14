<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Интерфейс в Domain, реализация (HTTP) — Infrastructure/Connector/Ozon\
 * OzonProductListClient. Тот же приём, что у OzonPostingsFetcher: Application
 * зависит от интерфейса, не от конкретного HTTP-клиента.
 *
 * Общей абстракции коннектора площадки здесь по-прежнему нет — она
 * появляется после второго маркетплейса, не после второго эндпоинта
 * одного и того же (CLAUDE.md, «не абстрагировать до второго случая»).
 */
interface OzonCatalogFetcher
{
    /**
     * Одна страница каталога. Возвращает тело ответа как есть, без разбора.
     *
     * $lastId — курсор площадки: пустая строка означает первую страницу.
     * Курсор, а не offset, потому что так устроен сам эндпоинт; выбора
     * между ними у нас нет.
     */
    public function fetchPage(string $clientId, string $apiKey, string $lastId, int $limit = 1000): string;
}
