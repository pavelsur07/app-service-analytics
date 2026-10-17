<?php

declare(strict_types=1);

namespace App\SalesPlanning\Domain;

use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Шапка одной версии плана продаж (ADR-040 п. 3, 8).
 *
 * **Граница согласованности.** Строки плана правятся только через план:
 * любая их правка отмечается здесь (`recordLinesChanged`), и ORM
 * повышает версию шапки. Сам он этого не сделает — изменилась бы только
 * строка, а не шапка, и утверждение по версии пропустило бы параллельно
 * добавленные дни (ADR-040 п. 10).
 *
 * **Валюта одна на план** и живёт здесь, а не в строке: план в двух
 * валютах невозможен структурно, а не проверкой (п. 6).
 *
 * **Период не меняется.** Другой период — другой план (п. 3); строки
 * плотные, и смена границ означала бы досоздание или потерю дней.
 *
 * Пишется ORM (CLAUDE.md §6): план вводит человек. Суррогатный ключ —
 * запрет из §2 написан для фактов.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_plan')]
#[ORM\Index(name: 'idx_sales_plan_company_account', columns: ['company_id', 'marketplace_account_id'])]
#[ORM\Index(name: 'idx_sales_plan_company_created_by', columns: ['company_id', 'created_by_user_id'])]
// Не больше одного черновика и одного утверждённого в линии (п. 8).
// Штатный сценарий до индексов не доходит — они страховка: нарушение
// на flush закрывает EntityManager. `where` — в форме, которую
// возвращает интроспекция PostgreSQL (patterns.md, «Частичный
// уникальный индекс»).
#[ORM\UniqueConstraint(
    name: 'uq_sales_plan_lineage_draft',
    columns: ['company_id', 'lineage_id'],
    options: ['where' => "((status)::text = 'draft'::text)"],
)]
#[ORM\UniqueConstraint(
    name: 'uq_sales_plan_lineage_approved',
    columns: ['company_id', 'lineage_id'],
    options: ['where' => "((status)::text = 'approved'::text)"],
)]
class SalesPlan
{
    /**
     * Стартовый лимит периода (ADR-040 п. 13): сезон с запасом.
     */
    public const int MaxPeriodDays = 184;

    public const int MaxNameLength = 200;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $marketplaceAccountId;

    #[ORM\Column(length: self::MaxNameLength)]
    private readonly string $name;

    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $dateFrom;

    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $dateTo;

    // options: ['fixed' => true] — ADR-004 требует именно char(3).
    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private readonly string $currency;

    #[ORM\Column(length: 16, enumType: SalesPlanStatus::class)]
    private SalesPlanStatus $status;

    // id первой версии линии; у первой версии совпадает с id.
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $lineageId;

    #[ORM\Column]
    private readonly int $versionNumber;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $createdByUserId;

    #[ORM\Column]
    private readonly \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    private function __construct(
        Uuid $id,
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $name,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        string $currency,
        Uuid $lineageId,
        int $versionNumber,
        Uuid $createdByUserId,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->name = $name;
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->currency = $currency;
        $this->status = SalesPlanStatus::Draft;
        $this->lineageId = $lineageId;
        $this->versionNumber = $versionNumber;
        $this->createdByUserId = $createdByUserId;
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    /**
     * Первая версия новой линии — черновик.
     *
     * Даты — календарные дни в часовом поясе площадки (п. 5): время
     * отбрасывается, чтобы 15 июля 23:00 и 15 июля 00:00 были одним днём.
     */
    public static function draft(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $name,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        string $currency,
        Uuid $createdByUserId,
        \DateTimeImmutable $createdAt,
    ): self {
        $dateFrom = $dateFrom->setTime(0, 0);
        $dateTo = $dateTo->setTime(0, 0);

        if ($dateTo < $dateFrom) {
            throw new \InvalidArgumentException('Конец периода плана раньше начала.');
        }

        $days = self::daysBetween($dateFrom, $dateTo);
        if ($days > self::MaxPeriodDays) {
            throw new \InvalidArgumentException(\sprintf('Период плана — %d дней, больше предела %d.', $days, self::MaxPeriodDays));
        }

        $id = Uuid::v7();

        return new self(
            $id,
            $companyId,
            $marketplaceAccountId,
            self::validName($name),
            $dateFrom,
            $dateTo,
            // Неизвестный код валюты — исключение здесь, а не строка
            // без смысла в базе (ADR-004: валюта обязательна и настоящая).
            Money::ofMinor(0, $currency)->currency(),
            $id,
            1,
            $createdByUserId,
            $createdAt,
        );
    }

    /**
     * Отметка правки строк: меняет поле шапки, и ORM повышает её версию
     * при flush (п. 10). Вызывается каждой операцией над строками.
     */
    public function recordLinesChanged(\DateTimeImmutable $changedAt): void
    {
        $this->assertEditable();
        $this->updatedAt = $changedAt;
    }

    public function assertEditable(): void
    {
        if (SalesPlanStatus::Draft !== $this->status) {
            throw new \DomainException(\sprintf('План в статусе %s не правится: правка — только у черновика.', $this->status->value));
        }
    }

    public function covers(\DateTimeImmutable $date): bool
    {
        $date = $date->setTime(0, 0);

        return $date >= $this->dateFrom && $date <= $this->dateTo;
    }

    public function periodDays(): int
    {
        return self::daysBetween($this->dateFrom, $this->dateTo);
    }

    /**
     * @return list<\DateTimeImmutable> дни периода по порядку
     */
    public function days(): array
    {
        $days = [];
        for ($day = $this->dateFrom; $day <= $this->dateTo; $day = $day->modify('+1 day')) {
            $days[] = $day;
        }

        return $days;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function marketplaceAccountId(): Uuid
    {
        return $this->marketplaceAccountId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function dateFrom(): \DateTimeImmutable
    {
        return $this->dateFrom;
    }

    public function dateTo(): \DateTimeImmutable
    {
        return $this->dateTo;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function status(): SalesPlanStatus
    {
        return $this->status;
    }

    public function lineageId(): Uuid
    {
        return $this->lineageId;
    }

    public function versionNumber(): int
    {
        return $this->versionNumber;
    }

    public function createdByUserId(): Uuid
    {
        return $this->createdByUserId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function version(): int
    {
        return $this->version;
    }

    private static function daysBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $diff = $from->diff($to);
        \assert(false !== $diff->days);

        return $diff->days + 1;
    }

    private static function validName(string $name): string
    {
        $name = trim($name);
        if ('' === $name) {
            throw new \InvalidArgumentException('У плана должно быть название.');
        }

        if (mb_strlen($name) > self::MaxNameLength) {
            throw new \InvalidArgumentException(\sprintf('Название плана длиннее %d символов.', self::MaxNameLength));
        }

        return $name;
    }
}
