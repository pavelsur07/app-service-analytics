<?php

declare(strict_types=1);

namespace App\Links\Domain;

/** Действия принадлежат Links; Identity только хранит журнал. */
final class ShortLinkAuditAction
{
    public const string DetailsChanged = 'short_link.details_changed';
    public const string Activated = 'short_link.activated';
    public const string Disabled = 'short_link.disabled';

    private function __construct()
    {
    }
}
