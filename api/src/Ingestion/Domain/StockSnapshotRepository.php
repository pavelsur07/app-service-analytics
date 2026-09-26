<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Symfony\Component\Uid\Uuid;

interface StockSnapshotRepository
{
    /**
     * Заменяет снимок дня целиком (ADR-034, CLAUDE.md §6): в одной
     * транзакции отметка дня вставляется (INSERT … ON CONFLICT DO NOTHING)
     * и блокируется (FOR UPDATE); если записанный started_at не раньше
     * нового — прогон устарел, ничего не меняется; иначе строки дня
     * удаляются, вставляется полный набор, отметка обновляется.
     *
     * Все $facts обязаны принадлежать $companyId, подключению и дню.
     *
     * @param list<string>            $requestedSkus
     * @param list<Uuid>              $rawDocumentIds
     * @param list<StockSnapshotFact> $facts
     *
     * @return bool true — день заменён; false — прогон устарел
     */
    public function replaceDay(
        string $companyId,
        Uuid $marketplaceAccountId,
        \DateTimeImmutable $snapshotDate,
        \DateTimeImmutable $startedAt,
        array $requestedSkus,
        array $rawDocumentIds,
        array $facts,
    ): bool;
}
