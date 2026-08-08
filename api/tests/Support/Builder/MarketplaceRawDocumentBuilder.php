<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-005: валидные умолчания, неизменяем, разделяет build() и persistWith().
 * companyId/marketplaceAccountId — независимые Uuid по умолчанию: Ingestion
 * не проверяет их существование в Identity на уровне БД (модули не связаны
 * FK, только по идентификатору).
 */
final class MarketplaceRawDocumentBuilder
{
    private Uuid $companyId;
    private Uuid $marketplaceAccountId;
    private string $reportType = 'ozon_posting_fbo_list';
    private \DateTimeImmutable $period;
    private string $rawBody = '{"result":[]}';

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->marketplaceAccountId = Uuid::v7();
        $this->period = new \DateTimeImmutable('2026-07-01');
    }

    public static function aMarketplaceRawDocument(): self
    {
        return new self();
    }

    public function withCompanyId(Uuid $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withMarketplaceAccountId(Uuid $marketplaceAccountId): self
    {
        $clone = clone $this;
        $clone->marketplaceAccountId = $marketplaceAccountId;

        return $clone;
    }

    public function withPeriod(\DateTimeImmutable $period): self
    {
        $clone = clone $this;
        $clone->period = $period;

        return $clone;
    }

    public function withRawBody(string $rawBody): self
    {
        $clone = clone $this;
        $clone->rawBody = $rawBody;

        return $clone;
    }

    public function build(): MarketplaceRawDocument
    {
        return MarketplaceRawDocument::capture(
            companyId: $this->companyId,
            marketplaceAccountId: $this->marketplaceAccountId,
            reportType: $this->reportType,
            period: $this->period,
            rawBody: $this->rawBody,
        );
    }

    public function persistWith(MarketplaceRawDocumentRepository $repository): MarketplaceRawDocument
    {
        $document = $this->build();
        $repository->add($document);

        return $document;
    }
}
