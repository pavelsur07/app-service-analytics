<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Symfony\Component\Uid\Uuid;

interface MarketplaceRawDocumentRepository
{
    /**
     * Идемпотентно: повторная загрузка документа с тем же
     * (company_id, marketplace_account_id, report_type, period, body_hash)
     * не создаёт вторую строку (ADR-006) — конфликт по уникальному индексу
     * молча пропускается, это не ошибка вызывающего кода.
     *
     * Возвращает id строки, которая теперь реально существует в базе —
     * не обязательно id самого переданного $document. При конфликте
     * (идентичный контент уже сохранён) сохраняется более ранняя запись,
     * и её id обязан пойти дальше на sales_fact.raw_document_id —
     * иначе прослеживаемость (ADR-006) сослалась бы на документ,
     * которого в raw-слое не существует.
     */
    public function add(MarketplaceRawDocument $document): Uuid;

    /** Возвращает точные сохранённые байты документа для повторного разбора. */
    public function body(string $companyId, Uuid $marketplaceAccountId, Uuid $id): string;

    /**
     * Признак «подключение хоть что-то загрузило» (удаление неиспользованных
     * подключений). Идёт по префиксу (company_id, marketplace_account_id)
     * существующего idx_marketplace_raw_document_ozon_history — новый
     * индекс не нужен.
     */
    public function existsForAccount(string $companyId, Uuid $marketplaceAccountId): bool;
}
