<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Rebuildable metadata projection; DBAL writes, no monetary fields or keys. */
#[ORM\Entity]
#[ORM\Table(name: 'ozon_campaign_catalog_snapshot')]
class OzonCampaignCatalogSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $accountId;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $rawDocumentId;

    #[ORM\Column(type: 'datetimetz_immutable', columnDefinition: 'TIMESTAMP(6) WITH TIME ZONE NOT NULL')]
    private readonly \DateTimeImmutable $receivedAt;

    /** @var list<array{id: string, title: ?string, state: string, advObjectType: string, PaymentType: ?string}> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private readonly array $campaigns;

    #[ORM\Column(nullable: true)]
    private readonly ?int $total;

    #[ORM\Column]
    private readonly bool $complete;

    #[ORM\Column]
    private readonly bool $restored;

    public function __construct(Uuid $companyId, Uuid $accountId, Uuid $rawDocumentId, \DateTimeImmutable $receivedAt, OzonCampaignCatalogData $data, bool $restored = false)
    {
        $this->companyId = $companyId;
        $this->accountId = $accountId;
        $this->rawDocumentId = $rawDocumentId;
        $this->receivedAt = $receivedAt;
        $this->campaigns = array_map(static fn (OzonCampaignCatalogItem $item): array => $item->metadata(), $data->items);
        $this->total = $data->total;
        $this->complete = $data->complete;
        $this->restored = $restored;
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function accountId(): Uuid
    {
        return $this->accountId;
    }

    public function rawDocumentId(): Uuid
    {
        return $this->rawDocumentId;
    }

    public function receivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    /** @return list<array{id: string, title: ?string, state: string, advObjectType: string, PaymentType: ?string}> */
    public function campaigns(): array
    {
        return $this->campaigns;
    }

    public function total(): ?int
    {
        return $this->total;
    }

    public function complete(): bool
    {
        return $this->complete;
    }

    public function restored(): bool
    {
        return $this->restored;
    }
}
