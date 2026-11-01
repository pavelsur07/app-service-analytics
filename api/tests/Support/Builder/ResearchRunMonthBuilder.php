<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\ProductResearchOzon\Domain\MonthSlice;
use App\ProductResearchOzon\Domain\ResearchRun;
use App\ProductResearchOzon\Domain\ResearchRunMonth;

final class ResearchRunMonthBuilder
{
    private ResearchRun $run;
    private MonthSlice $month;

    private function __construct()
    {
        $this->run = ResearchRunBuilder::aRun()->build();
        $this->month = new MonthSlice('2026-01', '2026-01-01', '2026-01-31', 31, false);
    }

    public static function aMonth(): self
    {
        return new self();
    }

    public function withRun(ResearchRun $run): self
    {
        $copy = clone $this;
        $copy->run = $run;

        return $copy;
    }

    public function withMonth(MonthSlice $month): self
    {
        $copy = clone $this;
        $copy->month = $month;

        return $copy;
    }

    public function build(): ResearchRunMonth
    {
        return new ResearchRunMonth($this->run, $this->month);
    }
}
