<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Application;

use App\ProductResearchOzon\Domain\CalendarSelection;

final readonly class CopyProjectInput
{
    public function __construct(public int $expectedVersion, public string $previewCalendarDate, public ?string $categoryPath = null, public ?string $name = null, public ?string $comment = null, public ?CalendarSelection $selection = null, public ?bool $includeCurrentMonth = null)
    {
    }
}
