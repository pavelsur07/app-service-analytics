<?php

declare(strict_types=1);

namespace App\Planning\Domain;

final readonly class PlanImportPreviewRow
{
    public function __construct(
        public int $rowNumber,
        public string $marketplaceSku,
        public string $businessDate,
        public int $quantity,
        public int $expectedVersion,
        public ?int $currentQuantity,
        public string $change,
    ) {
        if ($rowNumber < 2 || !DailyPlan::isMarketplaceSkuValid($marketplaceSku) || $quantity < 0 || $quantity > DailyPlan::MAX_QUANTITY || $expectedVersion < 0 || $expectedVersion > DailyPlan::MAX_MUTABLE_VERSION || !\in_array($change, ['new', 'changed', 'unchanged'], true)) {
            throw new \InvalidArgumentException('Строка preview некорректна.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate);
        if (false === $date || $date->format('Y-m-d') !== $businessDate) {
            throw new \InvalidArgumentException('Дата preview некорректна.');
        }
    }

    /** @return array{rowNumber: int, marketplaceSku: string, businessDate: string, quantity: int, expectedVersion: int, currentQuantity: ?int, change: string} */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array{rowNumber: int, marketplaceSku: string, businessDate: string, quantity: int, expectedVersion: int, currentQuantity: ?int, change: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }
}
