<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetQualityExact;

final readonly class NetQualityPairedObservation
{
    public function __construct(public string $caseId, public NetQualityExact $method, public NetQualityExact $simple, public NetQualityExact $truth, public ?string $currency = null, public ?NetQualityExact $methodMoney = null, public ?NetQualityExact $simpleMoney = null, public ?NetQualityExact $truthMoney = null)
    {
        if (1 !== preg_match('/^[a-f0-9]{64}$/D', $caseId) || $method->isNegative() || $simple->isNegative() || $truth->isNegative()) {
            throw new \InvalidArgumentException('Invalid case or negative retained quantity.');
        }
        $hasMoney = null !== $currency && null !== $methodMoney && null !== $simpleMoney && null !== $truthMoney;
        if (!$hasMoney && (null !== $currency || null !== $methodMoney || null !== $simpleMoney || null !== $truthMoney)) {
            throw new \InvalidArgumentException('Paired money requires both methods, truth, and one currency.');
        }
        if (null !== $currency && 1 !== preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new \InvalidArgumentException('Invalid paired currency.');
        }
    }
}
