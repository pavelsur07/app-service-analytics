<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

interface MarketplaceRawDocumentRepository
{
    /**
     * Идемпотентно: повторная загрузка документа с тем же
     * (company_id, marketplace_account_id, report_type, period, body_hash)
     * не создаёт вторую строку (ADR-006) — конфликт по уникальному индексу
     * молча пропускается, это не ошибка вызывающего кода.
     */
    public function add(MarketplaceRawDocument $document): void;
}
