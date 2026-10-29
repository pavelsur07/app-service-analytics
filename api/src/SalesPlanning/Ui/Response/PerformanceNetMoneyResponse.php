<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

use App\Shared\Domain\ValueObject\Money;
use OpenApi\Attributes as OA;

/** Exact integer minor units and the currency scale, including values beyond JS safe integers. */
final readonly class PerformanceNetMoneyResponse
{
    public function __construct(
        #[OA\Property(pattern: '^(?:0|-[1-9][0-9]*|[1-9][0-9]*)$')] public string $minor,
        public string $currency,
        // Installed ISO provider supports exponents 0, 2, 3 and 4; Money has no custom currency factory.
        #[OA\Property(minimum: 0, maximum: 4)] public int $fractionDigits,
    ) {
    }

    public static function of(?Money $money): ?self
    {
        return null === $money ? null : new self((string) $money->minorAmount(), $money->currency(), $money->fractionDigits());
    }
}
