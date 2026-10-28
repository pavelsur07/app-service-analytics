<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Message;

use App\Ingestion\Domain\FactCaptureOrigin;

/**
 * Одно последовательное окно возвратов одного кабинета, обе даты включены.
 * В отличие от postings окно нельзя дробить на конкурентные дни: cursor
 * обслуживает весь диапазон, а account-lock исключает пересечение окон.
 */
final readonly class FetchOzonReturnsMessage
{
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
        public string $from,
        public string $to,
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
        return ['companyId' => $this->companyId, 'marketplaceAccountId' => $this->marketplaceAccountId, 'from' => $this->from, 'to' => $this->to];
    }

    /** @param array{companyId: string, marketplaceAccountId: string, from: string, to: string} $data */
    public function __unserialize(array $data): void
    {
        $this->companyId = $data['companyId'];
        $this->marketplaceAccountId = $data['marketplaceAccountId'];
        $this->from = $data['from'];
        $this->to = $data['to'];
        $this->origin = FactCaptureOrigin::Unknown;
    }
}
