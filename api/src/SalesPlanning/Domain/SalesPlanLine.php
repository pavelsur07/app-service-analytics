<?php

declare(strict_types=1);

namespace App\SalesPlanning\Domain;

use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Строка плана: SKU × день → штуки и РРЦ за штуку (ADR-040 п. 3).
 *
 * Строки плотные: у SKU плана есть строка на каждый день периода,
 * в том числе с нулём штук. Неделя, месяц и весь план — сумма дней
 * при чтении; выручка (`штуки × цена`) не хранится — хранимое
 * произведение разошлось бы с множителями при первой правке.
 *
 * Валюта цены — валюта плана, колонкой шапки (п. 6): `Money` строки
 * собирается из `price_minor` здесь и `currency` там.
 *
 * Ключ суррогатный, естественный — уникальным индексом (п. 3): дата
 * в составном идентификаторе Doctrine не годится в ключ карты
 * идентичности, а строку пишет ORM.
 *
 * Правится только через план: каждая операция отмечает правку
 * на шапке (`SalesPlan::recordLinesChanged`), и версия шапки растёт.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_plan_line')]
#[ORM\UniqueConstraint(
    name: 'uq_sales_plan_line_plan_sku_date',
    columns: ['company_id', 'sales_plan_id', 'marketplace_sku', 'plan_date'],
)]
class SalesPlanLine
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $salesPlanId;

    // Тот же тип и та же длина, что у marketplace_listing и sales_fact:
    // будущее сравнение с фактом соединяет их по этой колонке (п. 4).
    #[ORM\Column(length: 64)]
    private readonly string $marketplaceSku;

    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $planDate;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column(type: 'money_minor_amount')]
    private int $priceMinor;

    private function __construct(
        SalesPlan $plan,
        string $marketplaceSku,
        \DateTimeImmutable $planDate,
        int $quantity,
        Money $price,
    ) {
        $planDate = $planDate->setTime(0, 0);
        if (!$plan->covers($planDate)) {
            throw new \InvalidArgumentException(\sprintf('День %s вне периода плана.', $planDate->format('Y-m-d')));
        }

        $this->id = Uuid::v7();
        $this->companyId = $plan->companyId();
        $this->salesPlanId = $plan->id();
        $this->marketplaceSku = self::validSku($marketplaceSku);
        $this->planDate = $planDate;
        $this->quantity = self::validQuantity($quantity);
        $this->priceMinor = self::validPriceMinor($plan, $price);
    }

    /**
     * Ввод SKU за весь период: штуки раскладываются по дням (п. 7),
     * цена копируется в каждый день — она не делится.
     *
     * @return list<self> по строке на каждый день периода
     */
    public static function spread(
        SalesPlan $plan,
        string $marketplaceSku,
        int $totalQuantity,
        Money $price,
        EvenDayDistribution $distribution,
        \DateTimeImmutable $changedAt,
    ): array {
        $days = $plan->days();
        $quantities = $distribution->split($totalQuantity, \count($days));

        $lines = [];
        foreach ($days as $index => $day) {
            $lines[] = new self($plan, $marketplaceSku, $day, $quantities[$index], $price);
        }

        $plan->recordLinesChanged($changedAt);

        return $lines;
    }

    /**
     * Правка отдельного дня черновика — например, акция с другой ценой.
     */
    public function changeTo(SalesPlan $plan, int $quantity, Money $price, \DateTimeImmutable $changedAt): void
    {
        $this->assertBelongsTo($plan);
        $quantity = self::validQuantity($quantity);
        $priceMinor = self::validPriceMinor($plan, $price);

        $plan->recordLinesChanged($changedAt);
        $this->quantity = $quantity;
        $this->priceMinor = $priceMinor;
    }

    public function price(SalesPlan $plan): Money
    {
        $this->assertBelongsTo($plan);

        return Money::ofMinor($this->priceMinor, $plan->currency());
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function salesPlanId(): Uuid
    {
        return $this->salesPlanId;
    }

    public function marketplaceSku(): string
    {
        return $this->marketplaceSku;
    }

    public function planDate(): \DateTimeImmutable
    {
        return $this->planDate;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    private function assertBelongsTo(SalesPlan $plan): void
    {
        if (!$plan->id()->equals($this->salesPlanId) || !$plan->companyId()->equals($this->companyId)) {
            throw new \InvalidArgumentException('Строка принадлежит другому плану.');
        }
    }

    private static function validSku(string $marketplaceSku): string
    {
        $marketplaceSku = trim($marketplaceSku);
        if ('' === $marketplaceSku || \strlen($marketplaceSku) > 64) {
            throw new \InvalidArgumentException('Артикул площадки пуст или длиннее 64 символов.');
        }

        return $marketplaceSku;
    }

    private static function validQuantity(int $quantity): int
    {
        if ($quantity < 0) {
            throw new \InvalidArgumentException('Штук в дне плана не может быть меньше нуля.');
        }

        return $quantity;
    }

    private static function validPriceMinor(SalesPlan $plan, Money $price): int
    {
        if ($price->currency() !== $plan->currency()) {
            throw new \InvalidArgumentException(\sprintf('Цена в %s, а план в %s: смешивать валюты нельзя (ADR-004).', $price->currency(), $plan->currency()));
        }

        if ($price->minorAmount() <= 0) {
            throw new \InvalidArgumentException('РРЦ должна быть больше нуля.');
        }

        return $price->minorAmount();
    }
}
