<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

final readonly class OzonCampaignCatalogItem
{
    public function __construct(public string $id, public ?string $title, public string $state, public string $advObjectType, public ?string $paymentType)
    {
    }

    /** @param array<mixed> $row */
    public static function fromRaw(array $row): self
    {
        $id = $row['id'] ?? null;
        $state = $row['state'] ?? null;
        $type = $row['advObjectType'] ?? null;
        if (!\is_string($id) || 1 !== preg_match('/\A[1-9][0-9]{0,19}\z/', $id)
            || !\is_string($state) || '' === $state || \strlen($state) > 128
            || !\is_string($type) || '' === $type || \strlen($type) > 128) {
            throw new \UnexpectedValueException('Invalid campaign catalog item.');
        }
        $title = $row['title'] ?? null;
        $payment = $row['PaymentType'] ?? null;
        if ((null !== $title && (!\is_string($title) || \strlen($title) > 4096))
            || (null !== $payment && (!\is_string($payment) || \strlen($payment) > 64))) {
            throw new \UnexpectedValueException('Invalid campaign catalog metadata.');
        }

        return new self($id, $title, $state, $type, $payment);
    }

    /** @return array{id: string, title: ?string, state: string, advObjectType: string, PaymentType: ?string} */
    public function metadata(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'state' => $this->state, 'advObjectType' => $this->advObjectType, 'PaymentType' => $this->paymentType];
    }
}
