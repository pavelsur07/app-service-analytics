<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Application\NetForecast as Net;
use App\Ingestion\Domain\ValueObject\NetQualityExact;
use Symfony\Component\Uid\Uuid;

final class NetQualityPairedObservationBuilder
{
    private string $caseId;
    /** @var array{string, string, string, string, string, string} */
    private array $quantity = ['1', '1', '1', '1', '1', '1'];
    private ?string $currency = null;
    /** @var array{array{string, string}, array{string, string}, array{string, string}}|null */
    private ?array $money = null;

    private function __construct()
    {
        $this->caseId = hash('sha256', Uuid::v7()->toRfc4122());
    }

    public static function aPair(): self
    {
        return new self();
    }

    public function withQuantities(string $methodN, string $methodD, string $simpleN, string $simpleD, string $truthN, string $truthD): self
    {
        $clone = clone $this;
        $clone->quantity = [$methodN, $methodD, $simpleN, $simpleD, $truthN, $truthD];

        return $clone;
    }

    /** @param array{string, string} $method
     * @param array{string, string} $simple
     * @param array{string, string} $truth */
    public function withMoney(string $currency, array $method, array $simple, array $truth): self
    {
        $clone = clone $this;
        $clone->currency = $currency;
        $clone->money = [$method, $simple, $truth];

        return $clone;
    }

    public function build(): Net\NetQualityPairedObservation
    {
        [$mn, $md, $sn, $sd, $tn, $td] = $this->quantity;

        return new Net\NetQualityPairedObservation($this->caseId, NetQualityExact::fraction($mn, $md), NetQualityExact::fraction($sn, $sd), NetQualityExact::fraction($tn, $td), $this->currency, null === $this->money ? null : NetQualityExact::fraction(...$this->money[0]), null === $this->money ? null : NetQualityExact::fraction(...$this->money[1]), null === $this->money ? null : NetQualityExact::fraction(...$this->money[2]));
    }
}
