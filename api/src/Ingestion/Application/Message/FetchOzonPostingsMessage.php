<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Message;

/**
 * Синхронизация одного бизнес-дня одного подключения (ADR-006:
 * скользящее окно и расписание — за пределами tracer bullet, здесь —
 * ручной/одиночный запуск). businessDate — Y-m-d, часовой пояс Ozon
 * (Europe/Moscow, ADR-009) для since/to вычисляет обработчик.
 */
final readonly class FetchOzonPostingsMessage
{
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
        public string $businessDate,
        public string $origin = 'rescan',
        public ?string $regularWindowFrom = null,
        public ?string $regularWindowTo = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        if (!\is_string($data['companyId'] ?? null) || !\is_string($data['marketplaceAccountId'] ?? null) || !\is_string($data['businessDate'] ?? null)) {
            throw new \UnexpectedValueException('Invalid postings message payload.');
        }
        $this->companyId = $data['companyId'];
        $this->marketplaceAccountId = $data['marketplaceAccountId'];
        $this->businessDate = $data['businessDate'];
        $this->origin = \is_string($data['origin'] ?? null) ? $data['origin'] : 'legacy';
        $this->regularWindowFrom = \is_string($data['regularWindowFrom'] ?? null) ? $data['regularWindowFrom'] : null;
        $this->regularWindowTo = \is_string($data['regularWindowTo'] ?? null) ? $data['regularWindowTo'] : null;
    }
}
