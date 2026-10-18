<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

final readonly class SetSalesPlanSkusResult
{
    /**
     * @param list<string> $unknownSkus SKU, которых нет в каталоге подключения
     */
    private function __construct(
        public SetSalesPlanSkusOutcome $outcome,
        public ?int $version = null,
        public array $unknownSkus = [],
    ) {
    }

    public static function saved(int $version): self
    {
        return new self(SetSalesPlanSkusOutcome::Saved, $version);
    }

    /**
     * @param list<string> $unknownSkus
     */
    public static function unknownSkus(array $unknownSkus): self
    {
        return new self(SetSalesPlanSkusOutcome::UnknownSkus, unknownSkus: $unknownSkus);
    }

    public static function of(SetSalesPlanSkusOutcome $outcome): self
    {
        return new self($outcome);
    }
}
