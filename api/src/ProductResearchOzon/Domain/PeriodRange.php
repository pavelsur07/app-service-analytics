<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

final readonly class PeriodRange
{
    public string $name;

    public function __construct(public string $fromMonth, public string $toMonth, string $name = '')
    {
        foreach ([$fromMonth, $toMonth] as $month) {
            if (1 !== preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D', $month)) {
                throw new \InvalidArgumentException('invalid_month');
            }
        }
        if ($fromMonth > $toMonth || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('invalid_period');
        }
        $this->name = trim($name);
    }

    public function key(): string
    {
        return $this->fromMonth.'/'.$this->toMonth.'/'.$this->name;
    }
}
