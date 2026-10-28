<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Message;

use App\Ingestion\Domain\FactCaptureOrigin;

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
        private ?FactCaptureOrigin $origin = FactCaptureOrigin::Unknown,
    ) {
    }

    public function captureOrigin(): FactCaptureOrigin
    {
        // Native unserialize старых queued messages не вызывает constructor.
        return $this->origin ?? FactCaptureOrigin::Unknown;
    }

    /** @return array<string, string> */
    public function __serialize(): array
    {
        // Keep the exact legacy readonly wire; provenance travels in SerializerStamp.
        return ['companyId' => $this->companyId, 'marketplaceAccountId' => $this->marketplaceAccountId, 'businessDate' => $this->businessDate];
    }

    /** @param array{companyId: string, marketplaceAccountId: string, businessDate: string} $data */
    public function __unserialize(array $data): void
    {
        $this->companyId = $data['companyId'];
        $this->marketplaceAccountId = $data['marketplaceAccountId'];
        $this->businessDate = $data['businessDate'];
        $this->origin = FactCaptureOrigin::Unknown;
    }
}
