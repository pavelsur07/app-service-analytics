<?php

declare(strict_types=1);

namespace App\Planning\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'planning_daily_plan')]
#[ORM\UniqueConstraint(name: 'uq_planning_daily_plan_key', columns: ['company_id', 'marketplace_account_id', 'marketplace_sku', 'business_date'])]
#[ORM\Index(name: 'idx_planning_daily_plan_updated_by', columns: ['company_id', 'updated_by'])]
class DailyPlan
{
    public const int MAX_QUANTITY = 2_147_483_647;
    public const int MAX_VERSION = 2_147_483_647;
    public const int MAX_MUTABLE_VERSION = self::MAX_VERSION - 1;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $marketplaceAccountId;

    #[ORM\Column(length: 64)]
    private readonly string $marketplaceSku;

    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $businessDate;

    #[ORM\Column(nullable: true)]
    private ?int $quantity;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column]
    private readonly \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'uuid')]
    private Uuid $updatedBy;

    private function __construct(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $marketplaceSku,
        \DateTimeImmutable $businessDate,
        int $quantity,
        Uuid $updatedBy,
        \DateTimeImmutable $createdAt,
    ) {
        self::validateSku($marketplaceSku);
        self::validateQuantity($quantity);
        $this->id = Uuid::v7();
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->marketplaceSku = $marketplaceSku;
        $this->businessDate = $businessDate->setTime(0, 0);
        $this->quantity = $quantity;
        $this->updatedBy = $updatedBy;
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    public static function create(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $marketplaceSku,
        \DateTimeImmutable $businessDate,
        int $quantity,
        Uuid $updatedBy,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self($companyId, $marketplaceAccountId, $marketplaceSku, $businessDate, $quantity, $updatedBy, $createdAt);
    }

    public function changeQuantity(int $quantity, Uuid $updatedBy, \DateTimeImmutable $updatedAt): void
    {
        self::validateQuantity($quantity);
        $this->quantity = $quantity;
        $this->updatedBy = $updatedBy;
        $this->updatedAt = $updatedAt;
    }

    public function remove(Uuid $updatedBy, \DateTimeImmutable $updatedAt): void
    {
        $this->quantity = null;
        $this->updatedBy = $updatedBy;
        $this->updatedAt = $updatedAt;
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function marketplaceAccountId(): Uuid
    {
        return $this->marketplaceAccountId;
    }

    public function marketplaceSku(): string
    {
        return $this->marketplaceSku;
    }

    public function businessDate(): \DateTimeImmutable
    {
        return $this->businessDate;
    }

    public function quantity(): ?int
    {
        return $this->quantity;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function updatedBy(): Uuid
    {
        return $this->updatedBy;
    }

    public static function isMarketplaceSkuValid(string $marketplaceSku): bool
    {
        return 1 === preg_match('//u', $marketplaceSku)
            && '' !== trim($marketplaceSku)
            && mb_strlen($marketplaceSku) <= 64
            && 0 === preg_match('/[\x00-\x1F\x7F]/u', $marketplaceSku);
    }

    private static function validateQuantity(int $quantity): void
    {
        if ($quantity < 0 || $quantity > self::MAX_QUANTITY) {
            throw new \InvalidArgumentException('План продаж выходит за допустимый диапазон.');
        }
    }

    private static function validateSku(string $marketplaceSku): void
    {
        if (!self::isMarketplaceSkuValid($marketplaceSku)) {
            throw new \InvalidArgumentException('SKU некорректен.');
        }
    }
}
