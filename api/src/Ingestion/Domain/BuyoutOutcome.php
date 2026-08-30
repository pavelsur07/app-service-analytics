<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/** Канонические исходы SKU из ADR-019; pending/unknown представлены NULL. */
enum BuyoutOutcome: string
{
    case CancelledBeforeHandover = 'T1';
    case Delivered = 'D';
    case CancelledAfterHandover = 'T2';
    case PartialRefusal = 'P';
    case ClientReturn = 'R';
}
