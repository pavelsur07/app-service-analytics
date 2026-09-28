<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Расход одной рекламной кампании на один SKU за день (ADR-035).
 *
 * Первичный ключ — естественный, той же формы, что у всех факт-таблиц
 * (ADR-006): (company_id, marketplace_account_id, source_row_id), где
 * source_row_id склеен из кампании, дня и SKU. Кампания в ключе
 * обязательна: в одной кампании несколько SKU, и один SKU вправе стоять
 * в двух кампаниях сразу.
 *
 * Сумма — из SKU-отчёта площадки как есть, со знаком расхода
 * (отрицательная). Итог кампании из `by-day` здесь не делится: он —
 * сверка (ADR-035 п. 5), а не источник.
 *
 * source_received_at — когда мы получили ответ, из которого взята
 * текущая версия строки. Одна тройка приходит из нескольких
 * перекрывающихся отчётов, и побеждает полученный позже, а не
 * обработанный позже. Это не received_at строки raw: тот же ответ,
 * полученный повторно, дедуплицируется к более ранней строке raw, но
 * новее от этого быть не перестаёт.
 *
 * Не пишется ORM (persist/flush): факт-таблица, запись — DBAL upsert
 * (CLAUDE.md §6) через DoctrineAdSkuExpenseFactWriter. Класс существует
 * для migrations:diff/schema:validate/Builder тестов.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ad_sku_expense_fact')]
#[ORM\Index(name: 'idx_ad_sku_expense_fact_company_business_date', columns: ['company_id', 'business_date'])]
#[ORM\Index(name: 'idx_ad_sku_expense_fact_raw_document_id', columns: ['raw_document_id'])]
class AdSkuExpenseFact
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

    /** День расхода в часовом поясе площадки — тот же, что у `by-day`. */
    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $businessDate;

    #[ORM\Column(length: 32)]
    private readonly string $campaignId;

    #[ORM\Column(length: 64)]
    private readonly string $marketplaceSku;

    #[ORM\Column(type: 'money_minor_amount')]
    private int $amountMinor;

    // options: ['fixed' => true] — ADR-004 требует именно char(3).
    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private readonly string $currency;

    #[ORM\Column(type: 'uuid')]
    private Uuid $rawDocumentId;

    #[ORM\Column]
    private \DateTimeImmutable $sourceReceivedAt;

    #[ORM\Column(length: 64)]
    private string $rowHash;

    #[ORM\Column]
    private readonly \DateTimeImmutable $firstLoadedAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastUpdatedAt;

    private function __construct(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        OzonAdSkuExpense $expense,
        Uuid $rawDocumentId,
        \DateTimeImmutable $sourceReceivedAt,
        \DateTimeImmutable $now,
    ) {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->sourceRowId = self::sourceRowId($expense->campaignId, $expense->businessDate, $expense->marketplaceSku);
        $this->businessDate = $expense->businessDate;
        $this->campaignId = $expense->campaignId;
        $this->marketplaceSku = $expense->marketplaceSku;
        $this->amountMinor = $expense->amount->minorAmount();
        $this->currency = $expense->amount->currency();
        $this->rawDocumentId = $rawDocumentId;
        $this->sourceReceivedAt = $sourceReceivedAt;
        $this->rowHash = self::computeRowHash($expense->amount);
        $this->firstLoadedAt = $now;
        $this->lastUpdatedAt = $now;
    }

    public static function normalize(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        OzonAdSkuExpense $expense,
        Uuid $rawDocumentId,
        \DateTimeImmutable $sourceReceivedAt,
    ): self {
        return new self($companyId, $marketplaceAccountId, $expense, $rawDocumentId, $sourceReceivedAt, new \DateTimeImmutable());
    }

    /**
     * Склейка ключа по ADR-035. Разделитель — вертикальная черта, как
     * у остальных фактов: идентификаторы кампании и SKU — цифры, дата —
     * Y-m-d.
     */
    public static function sourceRowId(string $campaignId, \DateTimeImmutable $businessDate, string $marketplaceSku): string
    {
        return $campaignId.'|'.$businessDate->format('Y-m-d').'|'.$marketplaceSku;
    }

    /**
     * Детектор изменений (ADR-006): всё, что может измениться у тройки
     * при пересчёте, — сумма и валюта. Кампания, день и SKU — ключ.
     */
    private static function computeRowHash(Money $amount): string
    {
        return hash('sha256', $amount->minorAmount().'|'.$amount->currency());
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function marketplaceAccountId(): Uuid
    {
        return $this->marketplaceAccountId;
    }

    public function sourceRowIdValue(): string
    {
        return $this->sourceRowId;
    }

    public function businessDate(): \DateTimeImmutable
    {
        return $this->businessDate;
    }

    public function campaignId(): string
    {
        return $this->campaignId;
    }

    public function marketplaceSku(): string
    {
        return $this->marketplaceSku;
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amountMinor, $this->currency);
    }

    public function rawDocumentId(): Uuid
    {
        return $this->rawDocumentId;
    }

    public function sourceReceivedAt(): \DateTimeImmutable
    {
        return $this->sourceReceivedAt;
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
