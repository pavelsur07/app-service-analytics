<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Immutable result of one report window, including successful empty loads. */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_day_load_receipt')]
#[ORM\UniqueConstraint(name: 'uq_day_load_receipt_id', columns: ['id'])]
#[ORM\Index(name: 'idx_day_load_receipt_window', columns: ['company_id', 'marketplace_account_id', 'report_type', 'marked_at', 'date_from', 'date_to'])]
class MarketplaceDayLoadReceipt
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $marketplaceAccountId;

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private readonly string $reportType;

    #[ORM\Id]
    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $dateFrom;

    #[ORM\Id]
    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $dateTo;

    #[ORM\Id]
    #[ORM\Column(type: 'datetime_immutable')]
    private readonly \DateTimeImmutable $markedAt;

    #[ORM\Id]
    #[ORM\Column(length: 16)]
    private readonly string $status;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $id;

    #[ORM\Column(length: 32)]
    private readonly string $captureVersion;

    #[ORM\Column(length: 32, enumType: FactCaptureOrigin::class, options: ['default' => 'unknown'])]
    private readonly FactCaptureOrigin $captureOrigin;

    private function __construct(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $reportType,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        \DateTimeImmutable $markedAt,
        string $status,
        Uuid $id,
        string $captureVersion,
        FactCaptureOrigin $captureOrigin,
    ) {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->reportType = $reportType;
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->markedAt = $markedAt;
        $this->status = $status;
        $this->id = $id;
        $this->captureVersion = $captureVersion;
        $this->captureOrigin = $captureOrigin;
    }
}
