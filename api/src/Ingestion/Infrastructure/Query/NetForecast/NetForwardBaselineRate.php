<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

final readonly class NetForwardBaselineRate
{
    public function __construct(public ?string $marketplaceSku, public bool $inputComplete, public ?string $grossQuantity, public ?string $returnedQuantity, public int $postings)
    {
    }

    /** @return array{marketplace_sku: ?string, input_complete: bool, gross_quantity: ?string, returned_quantity: ?string, postings: int} */
    public function toArray(): array
    {
        return ['marketplace_sku' => $this->marketplaceSku, 'input_complete' => $this->inputComplete, 'gross_quantity' => $this->grossQuantity, 'returned_quantity' => $this->returnedQuantity, 'postings' => $this->postings];
    }
}
