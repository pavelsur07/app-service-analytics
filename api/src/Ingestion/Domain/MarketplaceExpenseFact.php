<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Один расход площадки одного типа по одному товару (ADR-012).
 *
 * Первичный ключ — естественный, той же формы, что у всех факт-таблиц
 * (ADR-006): (company_id, marketplace_account_id, source_row_id), где
 * source_row_id склеен из accrual_id, артикула и типа начисления.
 * Склейка, а не пять колонок ключа: форма ключа одна на все факт-таблицы,
 * правило склейки — дело коннектора.
 *
 * Выручки и комиссии здесь нет намеренно: они уже лежат в sales_fact
 * из постингов, и второй экземпляр дал бы двойной счёт в первом же
 * отчёте (ADR-012).
 *
 * Не пишется ORM (persist/flush): факт-таблица, запись — DBAL upsert
 * (CLAUDE.md §6) через DoctrineMarketplaceExpenseFactWriter. Класс
 * существует для migrations:diff/schema:validate/Builder тестов.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_expense_fact')]
#[ORM\Index(name: 'idx_expense_fact_company_business_date', columns: ['company_id', 'business_date'])]
#[ORM\Index(name: 'idx_expense_fact_company_sku', columns: ['company_id', 'marketplace_sku'])]
#[ORM\Index(name: 'idx_expense_fact_raw_document_id', columns: ['raw_document_id'])]
class MarketplaceExpenseFact
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

    /**
     * День начисления в часовом поясе площадки — не дата продажи.
     * Расход по продаже начисляется позже неё, иногда на недели (ADR-012).
     */
    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $businessDate;

    /**
     * Пустая строка — расход, не привязанный к товару: реклама, хранение,
     * досрочная выплата. Не NULL: значение входит в склеенный ключ,
     * и пустое место в нём должно быть однозначным (ADR-012).
     */
    #[ORM\Column(length: 64)]
    private readonly string $marketplaceSku;

    /**
     * Идентификатор типа начисления из справочника площадки
     * (/v1/finance/accrual/types). Человеческое название — забота экрана,
     * не факт-таблицы: справочник общий для всех компаний и меняется
     * не вместе с фактами.
     */
    #[ORM\Column]
    private readonly int $feeTypeId;

    /**
     * Отправление или иная единица, к которой площадка отнесла начисление.
     * Хранится ради объяснимости: на вопрос «откуда эти 115 рублей»
     * ответ обязан называть отправление, а не только сумму.
     */
    #[ORM\Column(length: 64)]
    private readonly string $unitNumber;

    #[ORM\Column(type: 'money_minor_amount')]
    private int $amountMinor;

    // options: ['fixed' => true] — ADR-004 требует именно char(3).
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
        string $marketplaceSku,
        int $feeTypeId,
        string $unitNumber,
        Money $amount,
        Uuid $rawDocumentId,
        string $rowHash,
        \DateTimeImmutable $firstLoadedAt,
        \DateTimeImmutable $lastUpdatedAt,
    ) {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->sourceRowId = $sourceRowId;
        $this->businessDate = $businessDate;
        $this->marketplaceSku = $marketplaceSku;
        $this->feeTypeId = $feeTypeId;
        $this->unitNumber = $unitNumber;
        $this->amountMinor = $amount->minorAmount();
        $this->currency = $amount->currency();
        $this->rawDocumentId = $rawDocumentId;
        $this->rowHash = $rowHash;
        $this->firstLoadedAt = $firstLoadedAt;
        $this->lastUpdatedAt = $lastUpdatedAt;
    }

    public static function normalize(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        int $accrualId,
        \DateTimeImmutable $businessDate,
        string $marketplaceSku,
        int $feeTypeId,
        string $unitNumber,
        Money $amount,
        Uuid $rawDocumentId,
    ): self {
        $now = new \DateTimeImmutable();

        return new self(
            $companyId,
            $marketplaceAccountId,
            self::sourceRowId($accrualId, $marketplaceSku, $feeTypeId),
            $businessDate,
            $marketplaceSku,
            $feeTypeId,
            $unitNumber,
            $amount,
            $rawDocumentId,
            self::computeRowHash($businessDate, $unitNumber, $amount),
            $now,
            $now,
        );
    }

    /**
     * Склейка ключа по ADR-012. Разделитель — вертикальная черта, как
     * у продаж: артикул и тип начисления её не содержат, а accrual_id
     * число.
     */
    public static function sourceRowId(int $accrualId, string $marketplaceSku, int $feeTypeId): string
    {
        return $accrualId.'|'.$marketplaceSku.'|'.$feeTypeId;
    }

    /**
     * Детектор изменений (ADR-006) — не входит в ключ, покрывает все
     * поля, которые могут измениться при пересчёте: сумму, валюту,
     * бизнес-дату и единицу отнесения. Ключевые поля исключены намеренно.
     *
     * Считать хэш от одной суммы было бы ошибкой: площадка вправе
     * переотнести начисление на другой день или к другому отправлению,
     * не тронув сумму, и такая правка прошла бы мимо — строка осталась бы
     * со старой датой, а объяснить клиенту расхождение стало бы нечем.
     */
    private static function computeRowHash(\DateTimeImmutable $businessDate, string $unitNumber, Money $amount): string
    {
        return hash('sha256', implode('|', [
            $businessDate->format('Y-m-d'),
            $unitNumber,
            $amount->minorAmount(),
            $amount->currency(),
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

    public function sourceRowIdValue(): string
    {
        return $this->sourceRowId;
    }

    public function businessDate(): \DateTimeImmutable
    {
        return $this->businessDate;
    }

    public function marketplaceSku(): string
    {
        return $this->marketplaceSku;
    }

    public function feeTypeId(): int
    {
        return $this->feeTypeId;
    }

    public function unitNumber(): string
    {
        return $this->unitNumber;
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amountMinor, $this->currency);
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
