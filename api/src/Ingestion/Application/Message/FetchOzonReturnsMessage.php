<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Message;

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
        public string $origin = 'rescan',
        public ?string $regularWindowFrom = null,
        public ?string $regularWindowTo = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        foreach (['companyId', 'marketplaceAccountId', 'from', 'to'] as $field) {
            if (!\is_string($data[$field] ?? null)) {
                throw new \UnexpectedValueException('Invalid returns message payload.');
            }
        }
        $this->companyId = $data['companyId'];
        $this->marketplaceAccountId = $data['marketplaceAccountId'];
        $this->from = $data['from'];
        $this->to = $data['to'];
        $this->origin = \is_string($data['origin'] ?? null) ? $data['origin'] : 'legacy';
        $this->regularWindowFrom = \is_string($data['regularWindowFrom'] ?? null) ? $data['regularWindowFrom'] : null;
        $this->regularWindowTo = \is_string($data['regularWindowTo'] ?? null) ? $data['regularWindowTo'] : null;
    }
}
