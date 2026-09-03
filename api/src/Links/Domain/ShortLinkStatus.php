<?php

declare(strict_types=1);

namespace App\Links\Domain;

enum ShortLinkStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
