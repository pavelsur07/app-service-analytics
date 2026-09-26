<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Отметка снимка остатков дня по подключению (ADR-034).
 *
 * Полный снимок дня есть, когда started_at не NULL. started_at — начало
 * прогона, давшего текущий снимок; first_started_at — начало первого
 * полного прогона дня, после заполнения не меняется (битемпоральность
 * снимочного факта — здесь, а не у строки). requested_skus отличает ноль
 * (SKU был в запросе, строки нет) от «неизвестно»; raw_document_ids —
 * пачки прогона, из которых собран снимок: по ним день восстановим из raw.
 *
 * Пишется только DoctrineStockSnapshotWriter в одной транзакции с заменой
 * дня; ORM persist/flush не используется.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stock_snapshot_run')]
// Межарендаторное чтение сторожа свежести (RecentStockSnapshotAccountsQuery)
// отбирает по времени — ведущий столбец started_at (CLAUDE.md §1).
#[ORM\Index(name: 'idx_stock_snapshot_run_started_at', columns: ['started_at'])]
class StockSnapshotRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $marketplaceAccountId;

    #[ORM\Id]
    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $snapshotDate;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $firstStartedAt = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $requestedSkus = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $rawDocumentIds = [];

    #[ORM\Column]
    private int $rowCount = 0;

    private function __construct(Uuid $companyId, Uuid $marketplaceAccountId, \DateTimeImmutable $snapshotDate)
    {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->snapshotDate = $snapshotDate;
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function marketplaceAccountId(): Uuid
    {
        return $this->marketplaceAccountId;
    }

    public function snapshotDate(): \DateTimeImmutable
    {
        return $this->snapshotDate;
    }

    public function startedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function firstStartedAt(): ?\DateTimeImmutable
    {
        return $this->firstStartedAt;
    }

    /** @return list<string> */
    public function requestedSkus(): array
    {
        return $this->requestedSkus;
    }

    /** @return list<string> */
    public function rawDocumentIds(): array
    {
        return $this->rawDocumentIds;
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }
}
