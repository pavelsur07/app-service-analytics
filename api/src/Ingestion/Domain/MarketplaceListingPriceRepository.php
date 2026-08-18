<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

interface MarketplaceListingPriceRepository
{
    /**
     * Записывает те цены, которые отличаются от последней известной
     * по артикулу; совпадающие не создают строки (ADR-015).
     *
     * $companyId первым параметром (CLAUDE.md §1).
     *
     * Условие «отличается» живёт внутри запроса, а не в ветке кода:
     * между чтением последней цены и вставкой два прогона прошли бы
     * проверку оба и завели две одинаковые строки (CLAUDE.md §4).
     *
     * Список целиком, не по строке за вызов: синхронизация приносит
     * шесть десятков артикулов разом, и запрос на каждый был бы
     * запросом в цикле (CLAUDE.md §6).
     *
     * @param list<MarketplaceListingPrice> $prices
     */
    public function recordChanged(string $companyId, array $prices): void;
}
