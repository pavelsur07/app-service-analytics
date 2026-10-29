<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Application\NetForecast\NetQualityObservation;
use Symfony\Component\Uid\Uuid;

final class NetQualityObservationBuilder
{
    /** @var array<string, mixed> */
    private array $data;

    private function __construct()
    {
        $this->data = ['companyId' => Uuid::v7()->toRfc4122(), 'accountId' => Uuid::v7()->toRfc4122(), 'orderNumber' => 'ORDER', 'sku' => 'SKU', 'businessDate' => '2027-01-03', 'readAtUtc' => '2027-01-04T09:00:00.000000Z', 'input' => ['kind' => 'input', 'requestId' => '01940000-0000-7000-8000-000000000001', 'artifactHash' => str_repeat('a', 64)], 'group' => 'order1_2', 'components' => ['source-1'], 'payload' => ['model' => null, 'reason' => 'insufficient_sample']];
    }

    public static function anObservation(): self
    {
        return new self();
    }

    public function with(string $field, mixed $value): self
    {
        $clone = clone $this;
        $clone->data[$field] = $value;

        return $clone;
    }

    public function build(): NetQualityObservation
    {
        return NetQualityObservation::fromArray($this->data);
    }
}
