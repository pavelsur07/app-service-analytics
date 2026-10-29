<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

/** Incremental paired sums, with a bounded duplicate guard, shared by both callers. */
final class NetQualityMetricsAccumulator
{
    private readonly NetQualityMetricAccumulator $method;
    private readonly NetQualityMetricAccumulator $simple;
    /** @var array<string, array<string, NetQualityMetricAccumulator>> */
    private array $currencies = [];
    /** @var array<string, true> */
    private array $seen = [];

    public function __construct()
    {
        $this->method = new NetQualityMetricAccumulator();
        $this->simple = new NetQualityMetricAccumulator();
    }

    public function add(NetQualityPairedObservation $row): void
    {
        if (\count($this->seen) >= 20000) {
            throw new \OverflowException('Paired population limit exceeded.');
        }
        if (isset($this->seen[$row->caseId])) {
            throw new \DomainException('A paired population cannot repeat a case.');
        }
        $this->seen[$row->caseId] = true;
        $this->method->add($row->method, $row->truth);
        $this->simple->add($row->simple, $row->truth);
        if (null !== $row->currency && null !== $row->methodMoney && null !== $row->simpleMoney && null !== $row->truthMoney) {
            $currency = $row->currency;
            $this->currencies[$currency] ??= ['methodExact' => new NetQualityMetricAccumulator(), 'simpleExact' => new NetQualityMetricAccumulator(), 'methodRounded' => new NetQualityMetricAccumulator(), 'simpleRounded' => new NetQualityMetricAccumulator()];
            $this->currencies[$currency]['methodExact']->add($row->methodMoney, $row->truthMoney);
            $this->currencies[$currency]['simpleExact']->add($row->simpleMoney, $row->truthMoney);
            $this->currencies[$currency]['methodRounded']->add($row->methodMoney->roundedMinor(), $row->truthMoney->roundedMinor());
            $this->currencies[$currency]['simpleRounded']->add($row->simpleMoney->roundedMinor(), $row->truthMoney->roundedMinor());
        }
    }

    public function result(): NetQualityMetricsResult
    {
        $money = [];
        ksort($this->currencies, \SORT_STRING);
        foreach ($this->currencies as $currency => $sums) {
            $money[$currency] = ['count' => $sums['methodExact']->result()['count'], 'methodExact' => $sums['methodExact']->result(), 'simpleExact' => $sums['simpleExact']->result(), 'methodRounded' => $sums['methodRounded']->result(), 'simpleRounded' => $sums['simpleRounded']->result()];
        }

        return new NetQualityMetricsResult(\count($this->seen), $this->method->result(), $this->simple->result(), $money);
    }
}
