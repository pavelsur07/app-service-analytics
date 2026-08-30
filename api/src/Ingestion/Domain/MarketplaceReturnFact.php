<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Наблюдаемое событие возврата Ozon (ADR-019).
 *
 * Это не продажа и не расход: факт используется как разметка исхода SKU.
 * Естественный ключ включает точное строковое представление returns[].id;
 * order_number + sku служат только связью с sales_fact и не уникальны.
 * Запись выполняется DBAL bulk upsert, ORM-класс нужен для схемы и тестов.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_return_fact')]
#[ORM\Index(name: 'idx_return_fact_order_sku', columns: ['company_id', 'marketplace_account_id', 'order_number', 'marketplace_sku'])]
#[ORM\Index(name: 'idx_return_fact_visual_changed', columns: ['company_id', 'visual_status_changed_at'])]
#[ORM\Index(name: 'idx_return_fact_raw_document', columns: ['company_id', 'raw_document_id'])]
class MarketplaceReturnFact
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $marketplaceAccountId;

    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    private readonly string $sourceRowId;

    #[ORM\Column(length: 64)]
    private string $orderNumber;

    #[ORM\Column(length: 64)]
    private string $marketplaceSku;

    #[ORM\Column(length: 64)]
    private string $returnType;

    #[ORM\Column(type: 'text')]
    private string $returnReasonName;

    /**
     * Наблюдаемый posting_number сохраняется для trace. API не доказывает,
     * что это «дочернее отправление», поэтому такой семантики у поля нет.
     */
    #[ORM\Column(length: 64)]
    private string $postingNumber;

    #[ORM\Column(type: 'bigint')]
    private int $sourceId;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column]
    private int $visualStatusId;

    #[ORM\Column(length: 64)]
    private string $visualStatus;

    /**
     * Последняя смена обработки возврата, не business/outcome date.
     * Дата когорты берётся из связанной sales_fact.
     */
    #[ORM\Column]
    private \DateTimeImmutable $visualStatusChangedAt;

    #[ORM\Column(type: 'uuid')]
    private Uuid $rawDocumentId;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $rowHash;

    #[ORM\Column]
    private readonly \DateTimeImmutable $firstLoadedAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastUpdatedAt;

    private function __construct(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $sourceRowId,
        string $orderNumber,
        string $marketplaceSku,
        string $returnType,
        string $returnReasonName,
        string $postingNumber,
        int $sourceId,
        int $quantity,
        int $visualStatusId,
        string $visualStatus,
        \DateTimeImmutable $visualStatusChangedAt,
        Uuid $rawDocumentId,
        string $rowHash,
        \DateTimeImmutable $firstLoadedAt,
        \DateTimeImmutable $lastUpdatedAt,
    ) {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->sourceRowId = $sourceRowId;
        $this->orderNumber = $orderNumber;
        $this->marketplaceSku = $marketplaceSku;
        $this->returnType = $returnType;
        $this->returnReasonName = $returnReasonName;
        $this->postingNumber = $postingNumber;
        $this->sourceId = $sourceId;
        $this->quantity = $quantity;
        $this->visualStatusId = $visualStatusId;
        $this->visualStatus = $visualStatus;
        $this->visualStatusChangedAt = $visualStatusChangedAt;
        $this->rawDocumentId = $rawDocumentId;
        $this->rowHash = $rowHash;
        $this->firstLoadedAt = $firstLoadedAt;
        $this->lastUpdatedAt = $lastUpdatedAt;
    }

    public static function normalize(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $sourceRowId,
        string $orderNumber,
        string $marketplaceSku,
        string $returnType,
        string $returnReasonName,
        string $postingNumber,
        int $sourceId,
        int $quantity,
        int $visualStatusId,
        string $visualStatus,
        \DateTimeImmutable $visualStatusChangedAt,
        Uuid $rawDocumentId,
    ): self {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Return quantity must be positive.');
        }

        $now = new \DateTimeImmutable();

        return new self(
            $companyId,
            $marketplaceAccountId,
            $sourceRowId,
            $orderNumber,
            $marketplaceSku,
            $returnType,
            $returnReasonName,
            $postingNumber,
            $sourceId,
            $quantity,
            $visualStatusId,
            $visualStatus,
            $visualStatusChangedAt,
            $rawDocumentId,
            self::computeRowHash(
                $orderNumber,
                $marketplaceSku,
                $returnType,
                $returnReasonName,
                $postingNumber,
                $sourceId,
                $quantity,
                $visualStatusId,
                $visualStatus,
                $visualStatusChangedAt,
            ),
            $now,
            $now,
        );
    }

    private static function computeRowHash(
        string $orderNumber,
        string $marketplaceSku,
        string $returnType,
        string $returnReasonName,
        string $postingNumber,
        int $sourceId,
        int $quantity,
        int $visualStatusId,
        string $visualStatus,
        \DateTimeImmutable $visualStatusChangedAt,
    ): string {
        return hash('sha256', json_encode([
            $orderNumber,
            $marketplaceSku,
            $returnType,
            $returnReasonName,
            $postingNumber,
            $sourceId,
            $quantity,
            $visualStatusId,
            $visualStatus,
            $visualStatusChangedAt->format(\DateTimeInterface::ATOM),
        ], \JSON_THROW_ON_ERROR));
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function marketplaceAccountId(): Uuid
    {
        return $this->marketplaceAccountId;
    }

    public function sourceRowId(): string
    {
        return $this->sourceRowId;
    }

    public function orderNumber(): string
    {
        return $this->orderNumber;
    }

    public function marketplaceSku(): string
    {
        return $this->marketplaceSku;
    }

    public function returnType(): string
    {
        return $this->returnType;
    }

    public function returnReasonName(): string
    {
        return $this->returnReasonName;
    }

    public function postingNumber(): string
    {
        return $this->postingNumber;
    }

    public function sourceId(): int
    {
        return $this->sourceId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function visualStatusId(): int
    {
        return $this->visualStatusId;
    }

    public function visualStatus(): string
    {
        return $this->visualStatus;
    }

    public function visualStatusChangedAt(): \DateTimeImmutable
    {
        return $this->visualStatusChangedAt;
    }

    public function rawDocumentId(): Uuid
    {
        return $this->rawDocumentId;
    }

    public function rowHash(): string
    {
        return $this->rowHash;
    }

    public function firstLoadedAt(): \DateTimeImmutable
    {
        return $this->firstLoadedAt;
    }

    public function lastUpdatedAt(): \DateTimeImmutable
    {
        return $this->lastUpdatedAt;
    }
}
