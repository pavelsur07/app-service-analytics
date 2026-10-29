<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetQualityExact;

/** Flow and exact paired metrics are consumed with the same dataset row. */
final class NetQualityGroupAccumulator
{
    private int $candidates = 0;
    private int $ready = 0;
    private int $methodNull = 0;
    private int $simpleNull = 0;
    /** @var array<string, true> */
    private array $weeks = [];
    /** @var array<string, true> */
    private array $orders = [];
    /** @var array<string, int> */
    private array $flow = ['ready' => 0, 'pending' => 0, 'ineligible' => 0, 'censored' => 0, 'methodNullAllCandidates' => 0, 'simpleNullAllCandidates' => 0];

    public function __construct(private readonly NetQualityMetricsAccumulator $metrics)
    {
    }

    /** @param array<string, mixed> $row */
    public function add(array $row): void
    {
        if ($this->candidates >= 20000) {
            throw new \OverflowException('Quality group population limit exceeded.');
        }
        ++$this->candidates;
        $state = NetQualitySchema::string($row, 'truthState');
        $this->flow[$state] = ($this->flow[$state] ?? 0) + 1;
        if (true === ($row['censored'] ?? null)) {
            $this->flow['censored'] = ($this->flow['censored'] ?? 0) + 1;
        }
        $this->orders[NetQualitySchema::string($row, 'orderCluster')] = true;
        foreach (['truthReasons', 'methodReasons', 'simpleReasons'] as $field) {
            $reasons = $row[$field] ?? null;
            if (!\is_array($reasons)) {
                throw new \UnexpectedValueException('Missing dataset reasons.');
            }
            foreach ($reasons as $reason) {
                if (!\is_string($reason)) {
                    throw new \UnexpectedValueException('Invalid dataset reason.');
                }
                $key = $field.':'.$reason;
                $this->flow[$key] = ($this->flow[$key] ?? 0) + 1;
            }
        }
        if (\is_string($row['moneyReason'] ?? null)) {
            $key = 'moneyReason:'.$row['moneyReason'];
            $this->flow[$key] = ($this->flow[$key] ?? 0) + 1;
        }
        foreach (['method', 'simple'] as $name) {
            if (null === ($row[$name] ?? null)) {
                $this->flow[$name.'NullAllCandidates'] = ($this->flow[$name.'NullAllCandidates'] ?? 0) + 1;
            }
        }
        if ('ready' !== $state) {
            return;
        }
        ++$this->ready;
        $this->methodNull += null === ($row['method'] ?? null) ? 1 : 0;
        $this->simpleNull += null === ($row['simple'] ?? null) ? 1 : 0;
        if (!\is_array($row['method'] ?? null) || !\is_array($row['simple'] ?? null) || !\is_array($row['truthQuantity'] ?? null)) {
            return;
        }
        $this->weeks[NetQualitySchema::utc(NetQualitySchema::string($row, 'originReadAtUtc'))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('o-\WW')] = true;
        $this->metrics->add(new NetQualityPairedObservation(NetQualitySchema::string($row, 'caseId'), NetQualityExact::fromArray($row['method']), NetQualityExact::fromArray($row['simple']), NetQualityExact::fromArray($row['truthQuantity']), NetQualitySchema::nullableString($row['currency'] ?? null), \is_array($row['methodMoney'] ?? null) ? NetQualityExact::fromArray($row['methodMoney']) : null, \is_array($row['simpleMoney'] ?? null) ? NetQualityExact::fromArray($row['simpleMoney']) : null, \is_array($row['truthMoney'] ?? null) ? NetQualityExact::fromArray($row['truthMoney']) : null));
    }

    public function result(): NetQualityGroupResult
    {
        $this->flow['uniqueOrders'] = \count($this->orders);
        $this->flow['legacyTargetNotComparable'] = $this->candidates;

        return new NetQualityGroupResult($this->metrics->result(), array_keys($this->weeks), $this->candidates, $this->ready, $this->methodNull, $this->simpleNull, $this->flow);
    }
}
