<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Application;

use App\ProductResearchOzon\Domain\CalendarSelection;

final readonly class CreateProjectInput
{
    public function __construct(public string $categoryPath, public CalendarSelection $selection, public bool $includeCurrentMonth, public string $previewCalendarDate, public ?string $name = null, public string $comment = '')
    {
    }
}
