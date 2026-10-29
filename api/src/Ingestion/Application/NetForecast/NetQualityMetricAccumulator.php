<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetQualityExact;

/** Streaming sums for one fixed paired population. */
final class NetQualityMetricAccumulator
{
    private int $count = 0;
    private NetQualityExact $error;
    private NetQualityExact $absoluteError;
    private NetQualityExact $truthDenominator;

    public function __construct()
    {
        $this->error = $this->absoluteError = $this->truthDenominator = NetQualityExact::fraction('0');
    }

    public function add(NetQualityExact $prediction, NetQualityExact $truth): void
    {
        $error = $prediction->minus($truth);
        $this->error = $this->error->plus($error);
        $this->absoluteError = $this->absoluteError->plus($error->abs());
        $this->truthDenominator = $this->truthDenominator->plus($truth->abs());
        ++$this->count;
    }

    /** @return array<string, mixed> */
    public function result(): array
    {
        $n = NetQualityExact::fraction((string) max(1, $this->count));
        $mae = 0 === $this->count ? null : $this->absoluteError->dividedBy($n);
        $bias = 0 === $this->count ? null : $this->error->dividedBy($n);
        $wape = $this->truthDenominator->isZero() ? null : $this->absoluteError->dividedBy($this->truthDenominator);

        return ['count' => $this->count, 'sumError' => $this->error->toArray(), 'sumAbsoluteError' => $this->absoluteError->toArray(), 'truthDenominator' => $this->truthDenominator->toArray(), 'mae' => $mae?->toArray(), 'signedBias' => $bias?->toArray(), 'absoluteBias' => $bias?->abs()->toArray(), 'wape' => $wape?->toArray(), 'wapeReason' => null !== $wape ? null : (0 === $this->count ? 'empty_population' : 'zero_truth_denominator'), 'display' => ['mae' => $mae?->display(), 'signedBias' => $bias?->display(), 'absoluteBias' => $bias?->abs()->display(), 'wape' => $wape?->display()]];
    }
}
