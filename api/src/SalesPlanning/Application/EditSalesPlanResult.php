<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

final readonly class EditSalesPlanResult
{
    private function __construct(
        public EditSalesPlanOutcome $outcome,
        public ?int $version = null,
    ) {
    }

    public static function saved(int $version): self
    {
        return new self(EditSalesPlanOutcome::Saved, $version);
    }

    public static function of(EditSalesPlanOutcome $outcome): self
    {
        return new self($outcome);
    }
}
