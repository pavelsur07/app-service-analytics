<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\SalesPlanning\Domain\PlannedCalendarEvent;
use Symfony\Component\Uid\Uuid;

/** Existing id belongs to this version; new entries receive an application UUIDv7. */
final readonly class SalesPlanCalendarInput
{
    public function __construct(public ?Uuid $id, public PlannedCalendarEvent $event)
    {
    }
}
