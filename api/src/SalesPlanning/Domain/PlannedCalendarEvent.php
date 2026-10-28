<?php

declare(strict_types=1);

namespace App\SalesPlanning\Domain;

/** Ручная календарная отметка; границы сезона вычисляются отдельно из шапки. */
final readonly class PlannedCalendarEvent
{
    public \DateTimeImmutable $dateFrom;
    public \DateTimeImmutable $dateTo;

    public function __construct(
        public SalesPlanCalendarKind $kind,
        public ?string $marketplaceSku,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ) {
        if (\in_array($kind, [SalesPlanCalendarKind::SeasonStart, SalesPlanCalendarKind::SeasonEnd], true)) {
            throw new \InvalidArgumentException('Границы сезона вычисляются из периода плана.');
        }
        if (null !== $marketplaceSku && ('' === trim($marketplaceSku) || \strlen($marketplaceSku) > 64 || str_contains($marketplaceSku, "\0"))) {
            throw new \InvalidArgumentException('SKU календарной записи должен содержать от 1 до 64 символов.');
        }
        $utc = new \DateTimeZone('UTC');
        $this->dateFrom = new \DateTimeImmutable($dateFrom->format('Y-m-d'), $utc);
        $this->dateTo = new \DateTimeImmutable($dateTo->format('Y-m-d'), $utc);
        if ($this->dateFrom > $this->dateTo) {
            throw new \InvalidArgumentException('Начало календарной записи позже её конца.');
        }
        if (SalesPlanCalendarKind::Sales !== $kind && $this->dateFrom->format('Y-m-d') !== $this->dateTo->format('Y-m-d')) {
            throw new \InvalidArgumentException('Подсорт и стоп подсорт задаются одной датой.');
        }
    }

    public function assertWithin(SalesPlan $plan): void
    {
        if ($this->dateFrom->format('Y-m-d') < $plan->dateFrom()->format('Y-m-d')
            || $this->dateTo->format('Y-m-d') > $plan->dateTo()->format('Y-m-d')) {
            throw new \InvalidArgumentException('Календарная запись должна находиться внутри сезона.');
        }
    }
}
