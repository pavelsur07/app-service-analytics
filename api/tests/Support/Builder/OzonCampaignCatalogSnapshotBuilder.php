<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\OzonCampaignCatalogData;
use App\Ingestion\Domain\OzonCampaignCatalogItem;
use App\Ingestion\Domain\OzonCampaignCatalogRepository;
use App\Ingestion\Domain\OzonCampaignCatalogSnapshot;
use Symfony\Component\Uid\Uuid;

final class OzonCampaignCatalogSnapshotBuilder
{
    private Uuid $company;
    private Uuid $account;
    private Uuid $raw;
    private \DateTimeImmutable $at;
    private OzonCampaignCatalogData $data;

    private function __construct()
    {
        $this->company = Uuid::v7();
        $this->account = Uuid::v7();
        $this->raw = Uuid::v7();
        $this->at = new \DateTimeImmutable('2026-11-09T10:00:00Z');
        $this->data = new OzonCampaignCatalogData([new OzonCampaignCatalogItem('123', 'Тестовая кампания', 'CAMPAIGN_STATE_RUNNING', 'SKU', 'CPC')], 1, true);
    }

    public static function aSnapshot(): self
    {
        return new self();
    }

    public function withCompany(Uuid $company): self
    {
        $copy = clone $this;
        $copy->company = $company;

        return $copy;
    }

    public function withAccount(Uuid $account): self
    {
        $copy = clone $this;
        $copy->account = $account;

        return $copy;
    }

    public function withRaw(Uuid $raw): self
    {
        $copy = clone $this;
        $copy->raw = $raw;

        return $copy;
    }

    public function withReceivedAt(\DateTimeImmutable $at): self
    {
        $copy = clone $this;
        $copy->at = $at;

        return $copy;
    }

    public function withData(OzonCampaignCatalogData $data): self
    {
        $copy = clone $this;
        $copy->data = $data;

        return $copy;
    }

    public function build(): OzonCampaignCatalogSnapshot
    {
        return new OzonCampaignCatalogSnapshot($this->company, $this->account, $this->raw, $this->at, $this->data);
    }

    public function persistWith(OzonCampaignCatalogRepository $repository): OzonCampaignCatalogSnapshot
    {
        $snapshot = $this->build();
        $repository->replace($this->company->toRfc4122(), $snapshot);

        return $snapshot;
    }
}
