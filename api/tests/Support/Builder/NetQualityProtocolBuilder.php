<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Application\NetForecast\NetQualityProtocol;
use Symfony\Component\Uid\Uuid;

final class NetQualityProtocolBuilder
{
    /** @var array<string, mixed> */
    private array $data;

    private function __construct()
    {
        $slots = [];
        $truths = [];
        for ($i = 0; $i < 14; ++$i) {
            $id = Uuid::v7()->toRfc4122();
            $slots[] = ['requestId' => $id, 'scheduledAtUtc' => (new \DateTimeImmutable('2027-01-04T09:00:00Z'))->modify('+'.$i.' days')->format('Y-m-d\TH:i:s.u\Z')];
            $truths[] = ['requestId' => Uuid::v7()->toRfc4122(), 'inputId' => $id, 'scheduledAtUtc' => '2027-05-01T09:00:00.000000Z'];
        }
        $this->data = ['protocolId' => Uuid::v7()->toRfc4122(), 'companyId' => Uuid::v7()->toRfc4122(), 'accountId' => Uuid::v7()->toRfc4122(), 'planId' => Uuid::v7()->toRfc4122(), 'baselineId' => Uuid::v7()->toRfc4122(), 'codeCommit' => str_repeat('a', 40), 'epochId' => Uuid::v7()->toRfc4122(), 'epochStartUtc' => '2026-10-28T05:54:45.000000Z', 'from' => '2026-12-01', 'to' => '2027-01-17', 'calibrationFrom' => '2026-12-01', 'calibrationTo' => '2026-12-15', 'holdoutFrom' => '2027-01-02', 'holdoutTo' => '2027-01-17', 'captureFromUtc' => '2027-01-03T21:00:00.000000Z', 'captureToUtc' => '2027-01-17T21:00:00.000000Z', 'truthCutoffUtc' => '2027-05-02T00:00:00.000000Z', 'skus' => ['SKU'], 'currencies' => ['RUB'], 'slots' => $slots, 'truthSlots' => $truths];
    }

    public static function aProtocol(): self
    {
        return new self();
    }

    public function with(string $field, mixed $value): self
    {
        $clone = clone $this;
        $clone->data[$field] = $value;

        return $clone;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function build(): NetQualityProtocol
    {
        return new NetQualityProtocol($this->data);
    }
}
