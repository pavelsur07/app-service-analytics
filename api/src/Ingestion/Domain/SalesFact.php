<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Продажа по одному товару в одном отправлении Ozon (ADR-006, ADR-009).
 * Первичный ключ — естественный, без суррогата (ADR-003, ADR-006):
 * (company_id, marketplace_account_id, source_row_id).
 *
 * Не пишется ORM (persist/flush): факт-таблица, запись — DBAL upsert
 * (CLAUDE.md §6) через DoctrineSalesFactWriter. Класс существует для
 * migrations:diff/schema:validate/Builder тестов.
 *
 * status не сворачивается в business_date и не фильтруется при загрузке
 * (ADR-009) — витрина считает «заказано/доставлено/отменено» по этой
 * колонке явно.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_fact')]
#[ORM\Index(name: 'idx_sales_fact_company_business_date', columns: ['company_id', 'business_date'])]
#[ORM\Index(name: 'idx_sales_fact_raw_document_id', columns: ['raw_document_id'])]
#[ORM\Index(name: 'idx_sales_fact_posting', columns: ['company_id', 'marketplace_account_id', 'posting_number'])]
#[ORM\Index(name: 'idx_sales_fact_order_sku', columns: ['company_id', 'marketplace_account_id', 'order_number', 'marketplace_sku'])]
class SalesFact
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

    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $businessDate;

    #[ORM\Column(length: 32)]
    private string $status;

    #[ORM\Column(length: 64)]
    private readonly string $marketplaceSku;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $postingNumber;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $orderNumber;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column(type: 'money_minor_amount')]
    private int $amountMinor;

    #[ORM\Column(type: 'money_minor_amount')]
    private int $commissionAmountMinor;

    // options: ['fixed' => true] — ADR-004 требует именно char(3), не
    // varchar(3): без fixed Doctrine генерирует VARCHAR на любой длине.
    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private readonly string $currency;

    #[ORM\Column(type: 'uuid')]
    private Uuid $rawDocumentId;

    #[ORM\Column(length: 64)]
    private string $rowHash;

    #[ORM\Column]
    private readonly \DateTimeImmutable $firstLoadedAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastUpdatedAt;

    private function __construct(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $sourceRowId,
        \DateTimeImmutable $businessDate,
        string $status,
        string $marketplaceSku,
        ?string $postingNumber,
        ?string $orderNumber,
        int $quantity,
        Money $amount,
        Money $commissionAmount,
        Uuid $rawDocumentId,
        string $rowHash,
        \DateTimeImmutable $firstLoadedAt,
        \DateTimeImmutable $lastUpdatedAt,
    ) {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->sourceRowId = $sourceRowId;
        $this->businessDate = $businessDate;
        $this->status = $status;
        $this->marketplaceSku = $marketplaceSku;
        $this->postingNumber = $postingNumber;
        $this->orderNumber = $orderNumber;
        $this->quantity = $quantity;
        $this->amountMinor = $amount->minorAmount();
        $this->commissionAmountMinor = $commissionAmount->minorAmount();
        $this->currency = $amount->currency();
        $this->rawDocumentId = $rawDocumentId;
        $this->rowHash = $rowHash;
        $this->firstLoadedAt = $firstLoadedAt;
        $this->lastUpdatedAt = $lastUpdatedAt;
    }

    /**
     * $amount и $commissionAmount обязаны быть одной валюты — строка
     * факта хранит один код валюты (ADR-004: «все суммы одной строки
     * отчёта выражены в одной валюте»). Несовпадение — ошибка вызывающего
     * парсера, не повод для молчаливого выбора одной из двух.
     */
    public static function normalize(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $sourceRowId,
        \DateTimeImmutable $businessDate,
        string $status,
        string $marketplaceSku,
        int $quantity,
        Money $amount,
        Money $commissionAmount,
        Uuid $rawDocumentId,
        ?string $postingNumber = null,
        ?string $orderNumber = null,
    ): self {
        if ($amount->currency() !== $commissionAmount->currency()) {
            throw new \InvalidArgumentException('Amount and commission amount must share the same currency.');
        }
        $now = new \DateTimeImmutable();

        return new self(
            $companyId,
            $marketplaceAccountId,
            $sourceRowId,
            $businessDate,
            $status,
            $marketplaceSku,
            $postingNumber,
            $orderNumber,
            $quantity,
            $amount,
            $commissionAmount,
            $rawDocumentId,
            self::computeRowHash($status, $quantity, $amount, $commissionAmount, $postingNumber, $orderNumber),
            $now,
            $now,
        );
    }

    /**
     * Детектор изменений (ADR-006) — не входит в первичный ключ. Только
     * изменяемые поля: суммы, количество, статус. Ключевые/неизменяемые
     * поля исключены намеренно (ADR-006: суррогат/ключ строится из полей,
     * не меняющихся при корректировке).
     */
    private static function computeRowHash(
        string $status,
        int $quantity,
        Money $amount,
        Money $commissionAmount,
        ?string $postingNumber,
        ?string $orderNumber,
    ): string {
        return hash('sha256', implode('|', [
            $status,
            $quantity,
            $amount->minorAmount(),
            $commissionAmount->minorAmount(),
            $postingNumber ?? '<null>',
            $orderNumber ?? '<null>',
        ]));
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

    public function businessDate(): \DateTimeImmutable
    {
        return $this->businessDate;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function marketplaceSku(): string
    {
        return $this->marketplaceSku;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function postingNumber(): ?string
    {
        return $this->postingNumber;
    }

    public function orderNumber(): ?string
    {
        return $this->orderNumber;
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amountMinor, $this->currency);
    }

    public function commissionAmount(): Money
    {
        return Money::ofMinor($this->commissionAmountMinor, $this->currency);
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
